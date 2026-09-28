#!/usr/bin/env python3
"""Deterministic, machine-readable V7 logistic-model adapter.

Laravel owns dataset construction, lifecycle state and persistence. This
adapter owns fitting and inference only; stdout is JSON and diagnostics go to
stderr.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
import resource
import sys
from datetime import datetime
from typing import Any


MINIMUM_EFFECTIVE_FEATURES = 1


class ValidationCalibratedClassifier:
    """A persisted wrapper calibrated only from the chronological validation set."""

    def __init__(self, estimator, calibrator):
        self.estimator = estimator
        self.calibrator = calibrator

    def predict_proba(self, vectors):
        import numpy as np

        raw = self.estimator.predict_proba(vectors)[:, 1]
        calibrated = self.calibrator.predict_proba(raw.reshape(-1, 1))[:, 1]
        return np.column_stack([1.0 - calibrated, calibrated])


class IsotonicProbabilityAdapter:
    def __init__(self, fitted):
        self.fitted = fitted

    def predict_proba(self, values):
        import numpy as np

        positive = self.fitted.predict(values[:, 0])
        return np.column_stack([1.0 - positive, positive])


def read_request() -> dict[str, Any]:
    payload = json.load(sys.stdin)
    if not isinstance(payload, dict):
        raise ValueError("request must be a JSON object")
    return payload


def iter_jsonl(path: str):
    with open(path, "r", encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                row = json.loads(line)
                if not isinstance(row, dict):
                    raise ValueError(f"dataset row is not an object: {path}")
                yield row


def count_jsonl(path: str) -> int:
    return sum(1 for _ in iter_jsonl(path))


def peak_rss_mb() -> float:
    value = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    return round(float(value) / (1024 * 1024 if sys.platform == "darwin" else 1024), 2)


def finite(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) else None


def prepare(rows: list[dict[str, Any]], numeric: list[str], categorical: list[str], state: dict[str, Any] | None = None):
    if state is None:
        medians: dict[str, float] = {}
        for name in numeric:
            values = sorted(v for row in rows if (v := finite(row.get("features", {}).get(name))) is not None)
            if not values:
                raise ValueError(f"numeric feature has no training values: {name}")
            middle = len(values) // 2
            medians[name] = values[middle] if len(values) % 2 else (values[middle - 1] + values[middle]) / 2
        categories = {
            name: sorted({str(row.get("features", {}).get(name)) for row in rows if row.get("features", {}).get(name) is not None})
            for name in categorical
        }
        state = {"medians": medians, "categories": categories}

    vectors: list[list[float]] = []
    names: list[str] = []
    for name in numeric:
        names.extend([name, f"{name}__missing"])
    for name in categorical:
        names.extend([f"{name}={value}" for value in state["categories"].get(name, [])])
        names.append(f"{name}=__unknown")

    for row in rows:
        features = row.get("features", {})
        vector: list[float] = []
        for name in numeric:
            value = finite(features.get(name))
            vector.extend([state["medians"][name] if value is None else value, 1.0 if value is None else 0.0])
        for name in categorical:
            value = str(features.get(name)) if features.get(name) is not None else "__unknown"
            options = state["categories"].get(name, [])
            vector.extend([1.0 if value == option else 0.0 for option in options])
            vector.append(1.0 if value not in options else 0.0)
        vectors.append(vector)
    return vectors, names, state


def feature_names(numeric: list[str], categorical: list[str], state: dict[str, Any]) -> list[str]:
    names: list[str] = []
    for name in numeric:
        names.extend([name, f"{name}__missing"])
    for name in categorical:
        names.extend([f"{name}={value}" for value in state["categories"].get(name, [])])
        names.append(f"{name}=__unknown")
    return names


def state_from_jsonl(path: str, numeric: list[str], categorical: list[str]) -> dict[str, Any]:
    numeric_values = {name: [] for name in numeric}
    categories = {name: set() for name in categorical}
    for row in iter_jsonl(path):
        features = row.get("features", {})
        for name in numeric:
            value = finite(features.get(name))
            if value is not None:
                numeric_values[name].append(value)
        for name in categorical:
            value = features.get(name)
            if value is not None:
                categories[name].add(str(value))
    medians: dict[str, float] = {}
    for name, values in numeric_values.items():
        if not values:
            raise ValueError(f"numeric feature has no training values: {name}")
        values.sort()
        middle = len(values) // 2
        medians[name] = values[middle] if len(values) % 2 else (values[middle - 1] + values[middle]) / 2
    return {"medians": medians, "categories": {name: sorted(values) for name, values in categories.items()}}


def feature_coverage(rows, numeric: list[str], categorical: list[str], total_count: int) -> tuple[list[str], list[str], list[dict[str, Any]], dict[str, dict[str, int]]]:
    numeric_counts = {name: 0 for name in numeric}
    categorical_counts = {name: 0 for name in categorical}
    for row in rows:
        features = row.get("features", {})
        for name in numeric:
            if finite(features.get(name)) is not None:
                numeric_counts[name] += 1
        for name in categorical:
            value = features.get(name)
            if value is not None and str(value).strip() and str(value) != "__unknown":
                categorical_counts[name] += 1
    effective_numeric = [name for name in numeric if numeric_counts[name] > 0]
    effective_categorical = [name for name in categorical if categorical_counts[name] > 0]
    excluded = [
        {"feature": name, "reason": "no_training_values", "training_non_null_count": numeric_counts[name], "training_row_count": total_count}
        for name in numeric if numeric_counts[name] == 0
    ] + [
        {"feature": name, "reason": "no_training_values", "training_non_null_count": categorical_counts[name], "training_row_count": total_count}
        for name in categorical if categorical_counts[name] == 0
    ]
    coverage = {
        name: {"non_null": numeric_counts[name], "total": total_count}
        for name in numeric
    } | {
        name: {"non_null": categorical_counts[name], "total": total_count}
        for name in categorical
    }
    return effective_numeric, effective_categorical, excluded, coverage


def feature_coverage_from_jsonl(path: str, numeric: list[str], categorical: list[str], total_count: int):
    return feature_coverage(iter_jsonl(path), numeric, categorical, total_count)


def redundancy_diagnostics(vectors, names: list[str], threshold: float = 0.98) -> dict[str, Any]:
    """Report highly correlated encoded columns using training rows only."""
    import numpy as np

    if len(names) != vectors.shape[1] or vectors.shape[1] < 2:
        return {"threshold": threshold, "fit_partition": "train", "high_correlation_pairs": []}
    correlation = np.corrcoef(vectors, rowvar=False)
    pairs: list[dict[str, Any]] = []
    for left in range(len(names)):
        for right in range(left + 1, len(names)):
            value = finite(correlation[left, right])
            if value is not None and abs(value) >= threshold:
                pairs.append({"left": names[left], "right": names[right], "correlation": round(value, 6)})
    return {"threshold": threshold, "fit_partition": "train", "high_correlation_pairs": pairs}


def encode_row(row: dict[str, Any], numeric: list[str], categorical: list[str], state: dict[str, Any]) -> list[float]:
    features = row.get("features", {})
    vector: list[float] = []
    for name in numeric:
        value = finite(features.get(name))
        vector.extend([state["medians"][name] if value is None else value, 1.0 if value is None else 0.0])
    for name in categorical:
        value = str(features.get(name)) if features.get(name) is not None else "__unknown"
        options = state["categories"].get(name, [])
        vector.extend([1.0 if value == option else 0.0 for option in options])
        vector.append(1.0 if value not in options else 0.0)
    return vector


def matrix_from_jsonl(path: str, numeric: list[str], categorical: list[str], state: dict[str, Any], row_count: int, include_identities: bool = False):
    import numpy as np

    width = len(feature_names(numeric, categorical, state))
    vectors = np.empty((row_count, width), dtype=float)
    labels = np.empty(row_count, dtype=np.int8)
    relative_returns = np.full(row_count, np.nan, dtype=float)
    drawdowns = np.full(row_count, np.nan, dtype=float)
    identities: list[tuple[int, str]] = []
    index = 0
    for row in iter_jsonl(path):
        if index >= row_count:
            raise ValueError(f"JSONL row count changed while reading: {path}")
        vectors[index] = encode_row(row, numeric, categorical, state)
        labels[index] = int(row["label"])
        relative = finite(row.get("relative_return"))
        drawdown = finite(row.get("max_drawdown"))
        if relative is not None:
            relative_returns[index] = relative
        if drawdown is not None:
            drawdowns[index] = drawdown
        if include_identities:
            identities.append((int(row.get("stock_id", 0)), str(row.get("reference_date", ""))))
        index += 1
    if index != row_count:
        raise ValueError(f"JSONL row count changed while reading: {path}")
    return vectors, labels, relative_returns, drawdowns, identities


def compact_outcomes(rows: list[dict[str, Any]]):
    return (
        [int(row["label"]) for row in rows],
        [finite(row.get("relative_return")) for row in rows],
        [finite(row.get("max_drawdown")) for row in rows],
        [(int(row.get("stock_id", 0)), str(row.get("reference_date", ""))) for row in rows],
    )


def positive_label_count(labels) -> int:
    return sum(1 for label in labels if int(label) == 1)


def train_return_regressor(x_train, train_returns, x_validation, validation_returns, x_test, test_returns):
    import numpy as np
    from sklearn.linear_model import Ridge

    returns = np.asarray(train_returns, dtype=float)
    mask = ~np.isnan(returns)
    if int(mask.sum()) < 8:
        return None, {"status": "skipped", "reason": "insufficient_return_labels"}

    regressor = Ridge(alpha=1.0)
    regressor.fit(x_train[mask], returns[mask])

    def mean_abs_error(actual, predicted) -> float | None:
        errors: list[float] = []
        for truth, estimate in zip(actual, predicted):
            if truth is None or estimate is None:
                continue
            truth_value = finite(truth)
            estimate_value = finite(float(estimate))
            if truth_value is None or estimate_value is None:
                continue
            errors.append(abs(truth_value - estimate_value))
        return round(sum(errors) / len(errors), 6) if errors else None

    validation_pred = regressor.predict(x_validation)
    test_pred = regressor.predict(x_test)
    evidence = {
        "status": "trained",
        "model_family": "ridge_benchmark_relative_return",
        "validation_mae": mean_abs_error(validation_returns, validation_pred),
        "test_mae": mean_abs_error(test_returns, test_pred),
    }
    return regressor, evidence


def classification_metrics(y_true: list[int], probabilities: list[float], relative_returns: list[float | None], drawdowns: list[float | None], decisions: list[int] | None = None) -> dict[str, Any]:
    from sklearn.metrics import average_precision_score, brier_score_loss, precision_score, recall_score, roc_auc_score

    predicted = decisions if decisions is not None else [1 if value >= 0.5 else 0 for value in probabilities]
    unique = set(y_true)
    positive_count = positive_label_count(y_true)
    metrics: dict[str, Any] = {
        "class_distribution": {"positive": positive_count, "negative": len(y_true) - positive_count, "rows": len(y_true)},
        "precision": float(precision_score(y_true, predicted, zero_division=0)),
        "recall": float(recall_score(y_true, predicted, zero_division=0)),
        "calibration_error": float(brier_score_loss(y_true, probabilities)),
        "hit_rate": float(sum(a == b for a, b in zip(y_true, predicted)) / len(y_true)) if len(y_true) else 0.0,
    }
    if len(unique) < 2:
        raise ValueError("evaluation set contains one class")
    metrics["roc_auc"] = float(roc_auc_score(y_true, probabilities))
    metrics["pr_auc"] = float(average_precision_score(y_true, probabilities))
    returns = [value for value, prediction in zip(relative_returns, predicted) if prediction == 1 and value is not None]
    positive_drawdowns = [value for value, prediction in zip(drawdowns, predicted) if prediction == 1 and value is not None]
    metrics["benchmark_relative_return"] = sum(returns) / len(returns) if returns else 0.0
    metrics["max_drawdown"] = min(positive_drawdowns) if positive_drawdowns else 0.0
    metrics["deterministic_baseline_delta"] = 0.0
    return metrics


def reliability_buckets(y_true: list[int], probabilities: list[float], bins: int = 10) -> list[dict[str, Any]]:
    if len(y_true) == 0:
        return []
    bucket_count = max(1, min(bins, len(y_true)))
    ordered = sorted(zip(probabilities, y_true), key=lambda item: item[0])
    size = max(1, len(ordered) // bucket_count)
    buckets: list[dict[str, Any]] = []
    index = 0
    while index < len(ordered):
        slice_rows = ordered[index : index + size]
        if not slice_rows:
            break
        predicted_mean = sum(probability for probability, _ in slice_rows) / len(slice_rows)
        actual_rate = sum(label for _, label in slice_rows) / len(slice_rows)
        buckets.append(
            {
                "rows": len(slice_rows),
                "mean_predicted": round(predicted_mean, 6),
                "mean_actual": round(actual_rate, 6),
            }
        )
        index += size
    return buckets


def choose_calibration_method(validation_rows: int) -> str:
    return "isotonic" if validation_rows >= 200 else "sigmoid"


def fit_calibrated_classifier(model, x_validation, y_validation, validation_rows: int):
    from sklearn.linear_model import LogisticRegression

    sklearn_method = choose_calibration_method(validation_rows)
    raw_probabilities = model.predict_proba(x_validation)[:, 1]
    if sklearn_method == "isotonic":
        from sklearn.isotonic import IsotonicRegression

        calibrator = IsotonicRegression(out_of_bounds="clip")
        calibrator.fit(raw_probabilities, y_validation)
        calibrated = ValidationCalibratedClassifier(model, IsotonicProbabilityAdapter(calibrator))
    else:
        calibrator = LogisticRegression(max_iter=1000, random_state=7047)
        calibrator.fit(raw_probabilities.reshape(-1, 1), y_validation)
        calibrated = ValidationCalibratedClassifier(model, calibrator)
    product_method = "isotonic" if sklearn_method == "isotonic" else "platt_sigmoid"
    return calibrated, {"method": product_method, "sklearn_method": sklearn_method, "version": 2}


def calibration_evidence(
    y_true: list[int],
    uncalibrated: list[float],
    calibrated: list[float],
    method_meta: dict[str, Any],
) -> dict[str, Any]:
    from sklearn.metrics import brier_score_loss

    return {
        **method_meta,
        "uncalibrated_brier": float(brier_score_loss(y_true, uncalibrated)),
        "calibrated_brier": float(brier_score_loss(y_true, calibrated)),
        "reliability_buckets": reliability_buckets(y_true, calibrated),
    }


def contribution_source_model(model):
    if hasattr(model, "calibrated_classifiers_") and model.calibrated_classifiers_:
        return model.calibrated_classifiers_[0].estimator
    if hasattr(model, "estimator"):
        return model.estimator
    return model


def train(request: dict[str, Any]) -> dict[str, Any]:
    from sklearn.linear_model import LogisticRegression
    import numpy as np

    dataset_paths = request.get("dataset_paths")
    if isinstance(dataset_paths, dict):
        train_path = str(dataset_paths["train"])
        validation_path = str(dataset_paths["validation"])
        test_path = str(dataset_paths["test"])
        train_count = count_jsonl(train_path)
        validation_count = count_jsonl(validation_path)
        test_count = count_jsonl(test_path)
        total_count = train_count + validation_count + test_count
    else:
        rows = request.get("rows")
        train_rows = [row for row in rows if row.get("partition") == "train"] if isinstance(rows, list) else []
        validation_rows = [row for row in rows if row.get("partition") == "validation"] if isinstance(rows, list) else []
        test_rows = [row for row in rows if row.get("partition") == "test"] if isinstance(rows, list) else []
        train_count = len(train_rows)
        validation_count = len(validation_rows)
        test_count = len(test_rows)
        total_count = train_count + validation_count + test_count
    numeric = list(request.get("numeric_features", []))
    categorical = list(request.get("categorical_features", []))
    feature_profile = request.get("feature_profile")
    if isinstance(feature_profile, dict):
        profile_numeric = list(feature_profile.get("numeric", []))
        profile_categorical = list(feature_profile.get("categorical", []))
        if profile_numeric != numeric or profile_categorical != categorical:
            raise ValueError("feature profile does not match configured adapter feature lists")
        if feature_profile.get("horizon") is None or feature_profile.get("feature_set_id") is None:
            raise ValueError("feature profile must pin horizon and feature_set_id")
    if total_count < 12:
        raise ValueError("training dataset is too small")
    configured_numeric = numeric.copy()
    configured_categorical = categorical.copy()
    partitions = request.get("partitions", {})
    if not train_count or not validation_count or not test_count:
        raise ValueError("chronological train/validation/test partitions are required")

    if isinstance(dataset_paths, dict):
        numeric, categorical, excluded_features, feature_training_coverage = feature_coverage_from_jsonl(train_path, configured_numeric, configured_categorical, train_count)
        state = state_from_jsonl(train_path, numeric, categorical)
        x_train, y_train, train_returns, train_drawdowns, _ = matrix_from_jsonl(train_path, numeric, categorical, state, train_count)
        x_validation, y_validation, validation_returns, validation_drawdowns, _ = matrix_from_jsonl(validation_path, numeric, categorical, state, validation_count)
        x_test, y_test, test_returns, test_drawdowns, test_identities = matrix_from_jsonl(test_path, numeric, categorical, state, test_count, include_identities=True)
        feature_names_value = feature_names(numeric, categorical, state)
    else:
        numeric, categorical, excluded_features, feature_training_coverage = feature_coverage(train_rows, configured_numeric, configured_categorical, train_count)
        y_train, train_returns, train_drawdowns, _ = compact_outcomes(train_rows)
        y_validation, validation_returns, validation_drawdowns, _ = compact_outcomes(validation_rows)
        y_test, test_returns, test_drawdowns, test_identities = compact_outcomes(test_rows)
        x_train_list, feature_names_value, state = prepare(train_rows, numeric, categorical)
        x_validation_list, _, _ = prepare(validation_rows, numeric, categorical, state)
        x_test_list, _, _ = prepare(test_rows, numeric, categorical, state)
        x_train = np.asarray(x_train_list, dtype=float)
        x_validation = np.asarray(x_validation_list, dtype=float)
        x_test = np.asarray(x_test_list, dtype=float)

    if len(numeric) + len(categorical) < MINIMUM_EFFECTIVE_FEATURES:
        raise ValueError("training dataset has no usable configured features")

    redundancy = redundancy_diagnostics(x_train, feature_names_value, float(request.get("redundancy_threshold", 0.98)))

    if len(set(y_train)) < 2:
        raise ValueError("training set contains one class")
    model = LogisticRegression(class_weight="balanced", max_iter=1000, random_state=int(request.get("seed", 7047)))
    model.fit(x_train, y_train)
    validation_probabilities_uncalibrated = model.predict_proba(x_validation)[:, 1].tolist()
    test_probabilities_uncalibrated = model.predict_proba(x_test)[:, 1].tolist()
    calibrated_model, calibration_method = fit_calibrated_classifier(model, x_validation, y_validation, validation_count)
    validation_probabilities = calibrated_model.predict_proba(x_validation)[:, 1].tolist()
    test_probabilities = calibrated_model.predict_proba(x_test)[:, 1].tolist()
    calibration = calibration_evidence(
        y_validation,
        validation_probabilities_uncalibrated,
        validation_probabilities,
        calibration_method,
    )
    calibration["test_evidence"] = calibration_evidence(
        y_test,
        test_probabilities_uncalibrated,
        test_probabilities,
        calibration_method,
    )
    validation_metrics = classification_metrics(y_validation, validation_probabilities, validation_returns, validation_drawdowns)
    test_metrics = classification_metrics(y_test, test_probabilities, test_returns, test_drawdowns)
    validation_metrics["calibration"] = calibration
    test_metrics["calibration"] = calibration["test_evidence"]

    naive_probabilities = [positive_label_count(y_train) / len(y_train)] * test_count
    naive_metrics = classification_metrics(y_test, naive_probabilities, test_returns, test_drawdowns)
    baseline_path = request.get("deterministic_baseline_path")
    deterministic_probabilities: list[float] = []
    deterministic_decisions: list[int] = []
    if isinstance(baseline_path, str):
        with open(baseline_path, "r", encoding="utf-8") as handle:
            for expected_identity in test_identities:
                line = handle.readline()
                if not line:
                    raise ValueError("deterministic StoX baseline is not aligned to the test partition")
                item = json.loads(line)
                identity = (int(item.get("stock_id", 0)), str(item.get("reference_date", "")))
                if identity != expected_identity:
                    raise ValueError("deterministic StoX baseline identity does not match the test partition")
                deterministic_probabilities.append(float(item["probability"]))
                deterministic_decisions.append(1 if bool(item.get("positive_decision")) else 0)
            if handle.readline():
                raise ValueError("deterministic StoX baseline has extra rows")
    else:
        deterministic_rows = request.get("deterministic_baseline")
        if not isinstance(deterministic_rows, list) or len(deterministic_rows) != test_count:
            raise ValueError("deterministic StoX baseline is missing or not aligned to the test partition")
        for item, expected_identity in zip(deterministic_rows, test_identities):
            identity = (int(item.get("stock_id", 0)), str(item.get("reference_date", "")))
            if identity != expected_identity:
                raise ValueError("deterministic StoX baseline identity does not match the test partition")
            deterministic_probabilities.append(float(item["probability"]))
            deterministic_decisions.append(1 if bool(item.get("positive_decision")) else 0)
    deterministic_metrics = classification_metrics(y_test, deterministic_probabilities, test_returns, test_drawdowns, deterministic_decisions)
    test_metrics["deterministic_baseline_delta"] = test_metrics["benchmark_relative_return"] - deterministic_metrics["benchmark_relative_return"]
    challenger_evidence = {"model_family": "hist_gradient_boosting_challenger", "status": "skipped"}
    challenger = None
    if request.get("train_challenger", True):
        try:
            from sklearn.ensemble import HistGradientBoostingClassifier

            challenger = HistGradientBoostingClassifier(
                max_iter=int(request.get("challenger_max_iter", 120)),
                max_depth=int(request.get("challenger_max_depth", 4)),
                random_state=int(request.get("seed", 7047)),
            )
            challenger.fit(x_train, y_train)
            challenger_uncal_val = challenger.predict_proba(x_validation)[:, 1].tolist()
            challenger_uncal_test = challenger.predict_proba(x_test)[:, 1].tolist()
            calibrated_challenger, challenger_calibration_method = fit_calibrated_classifier(
                challenger,
                x_validation,
                y_validation,
                validation_count,
            )
            challenger_validation_probs = calibrated_challenger.predict_proba(x_validation)[:, 1].tolist()
            challenger_test_probs = calibrated_challenger.predict_proba(x_test)[:, 1].tolist()
            challenger_calibration = calibration_evidence(
                y_validation,
                challenger_uncal_val,
                challenger_validation_probs,
                challenger_calibration_method,
            )
            challenger_calibration["test_evidence"] = calibration_evidence(
                y_test,
                challenger_uncal_test,
                challenger_test_probs,
                challenger_calibration_method,
            )
            challenger_validation_metrics = classification_metrics(
                y_validation,
                challenger_validation_probs,
                validation_returns,
                validation_drawdowns,
            )
            challenger_validation_metrics["calibration"] = challenger_calibration
            challenger_test_metrics = classification_metrics(y_test, challenger_test_probs, test_returns, test_drawdowns)
            challenger_test_metrics["calibration"] = challenger_calibration["test_evidence"]
            challenger = calibrated_challenger
            challenger_evidence = {
                "model_family": "hist_gradient_boosting_challenger",
                "status": "trained",
                "validation_metrics": challenger_validation_metrics,
                "test_metrics": challenger_test_metrics,
                "calibration": challenger_calibration,
                "roc_auc_delta_vs_logistic": round(
                    float(challenger_test_metrics.get("roc_auc", 0.0)) - float(test_metrics.get("roc_auc", 0.0)),
                    6,
                ),
            }
        except Exception as exc:  # noqa: BLE001
            challenger_evidence = {
                "model_family": "hist_gradient_boosting_challenger",
                "status": "error",
                "message": str(exc),
            }
    return_regressor_model, return_regressor_evidence = train_return_regressor(
        x_train,
        train_returns,
        x_validation,
        validation_returns,
        x_test,
        test_returns,
    )
    metadata = {
        "format": "stox-v7-logistic",
        "feature_names": feature_names_value,
        "configured_numeric_features": configured_numeric,
        "configured_categorical_features": configured_categorical,
        "feature_profile": feature_profile,
        "numeric_features": numeric,
        "categorical_features": categorical,
        "effective_feature_set": numeric + categorical,
        "excluded_features": excluded_features,
        "feature_training_coverage": feature_training_coverage,
        "redundancy": redundancy,
        "preprocessing": state,
        "partitions": partitions,
        "runtime": {"python": sys.version.split()[0], "sklearn": __import__("sklearn").__version__},
        "seed": int(request.get("seed", 7047)),
        "challenger": challenger_evidence,
        "return_regressor": return_regressor_evidence,
        "calibration": calibration,
    }
    diagnostics = {
        "train_rows": train_count,
        "validation_rows": validation_count,
        "test_rows": test_count,
        "feature_count": len(feature_names_value),
        "raw_transport": "jsonl" if isinstance(dataset_paths, dict) else "json",
        "python_peak_rss_mb": peak_rss_mb(),
        "feature_training_coverage": feature_training_coverage,
        "excluded_features": excluded_features,
        "redundancy": redundancy,
    }
    metadata["diagnostics"] = diagnostics
    artifact_path = request.get("artifact_path")
    if not isinstance(artifact_path, str) or not artifact_path:
        raise ValueError("artifact_path is required")
    os.makedirs(os.path.dirname(artifact_path), exist_ok=True)
    import joblib

    joblib.dump({**metadata, "model": calibrated_model, "return_regressor": return_regressor_model}, artifact_path, compress=3)
    digest = hashlib.sha256(open(artifact_path, "rb").read()).hexdigest()
    challenger_digest = None
    challenger_path = request.get("challenger_artifact_path")
    if (
        challenger is not None
        and challenger_evidence.get("status") == "trained"
        and isinstance(challenger_path, str)
        and challenger_path
    ):
        challenger_metadata = {
            **metadata,
            "format": "stox-v8-hist-gradient-challenger",
            "inference_model_family": "hist_gradient_boosting_challenger",
        }
        os.makedirs(os.path.dirname(challenger_path), exist_ok=True)
        joblib.dump(
            {**challenger_metadata, "model": challenger, "return_regressor": return_regressor_model},
            challenger_path,
            compress=3,
        )
        challenger_digest = hashlib.sha256(open(challenger_path, "rb").read()).hexdigest()
    metrics = dict(test_metrics)
    metrics["validation"] = validation_metrics
    metrics["test"] = test_metrics
    metrics["train_rows"] = train_count
    metrics["validation_rows"] = validation_count
    metrics["test_rows"] = test_count
    return {
        "schema_version": 1,
        "artifact_sha256": digest,
        "challenger_artifact_sha256": challenger_digest,
        "metrics": metrics,
        "baselines": {"naive": naive_metrics, "deterministic_stox": deterministic_metrics},
        "metadata": metadata,
        "diagnostics": diagnostics,
    }


def predict(request: dict[str, Any]) -> dict[str, Any]:
    import joblib

    artifact_path = request.get("artifact_path")
    expected = request.get("artifact_sha256")
    if not isinstance(artifact_path, str) or not os.path.isfile(artifact_path):
        raise ValueError("model artifact is missing")
    digest = hashlib.sha256(open(artifact_path, "rb").read()).hexdigest()
    if expected and digest != expected:
        raise ValueError("model artifact integrity check failed")
    artifact = joblib.load(artifact_path)
    rows = [{"features": request.get("features", {})}]
    vectors, names, _ = prepare(rows, artifact["numeric_features"], artifact["categorical_features"], artifact["preprocessing"])
    if names != artifact["feature_names"]:
        raise ValueError("model feature schema does not match prediction input")
    inference_model = artifact["model"]
    probability = float(inference_model.predict_proba(vectors)[0, 1])
    contributions = []
    model = contribution_source_model(inference_model)
    if hasattr(model, "coef_"):
        for name, value, coefficient in zip(names, vectors[0], model.coef_[0].tolist()):
            contribution = float(value * coefficient)
            if contribution != 0:
                contributions.append({"feature": name, "value": value, "coefficient": coefficient, "contribution": contribution, "direction": "positive" if contribution > 0 else "negative"})
        contributions.sort(key=lambda item: abs(item["contribution"]), reverse=True)
    expected_return_pct = None
    regressor = artifact.get("return_regressor")
    if regressor is not None:
        estimate = finite(float(regressor.predict(vectors)[0]))
        if estimate is not None:
            expected_return_pct = round(estimate * 100, 4)
    return {
        "schema_version": 1,
        "score": round(probability * 100, 4),
        "confidence": round(max(probability, 1 - probability), 4),
        "expected_benchmark_relative_return_pct": expected_return_pct,
        "contributions": contributions[:20],
        "artifact_sha256": digest,
    }


def drift(request: dict[str, Any]) -> dict[str, Any]:
    rows = request.get("predictions", [])
    minimum = int(request.get("minimum_predictions", 30))
    scores = [finite(row.get("score")) for row in rows]
    scores = [value for value in scores if value is not None]
    age_days = request.get("model_age_days")
    age_warning_days = int(request.get("age_warning_days", 365))
    warnings: list[str] = []
    if isinstance(age_days, (int, float)) and age_days >= age_warning_days:
        warnings.append("model_age_exceeds_warning_threshold")
    if len(scores) < minimum:
        return {"schema_version": 1, "status": "warning" if warnings else "insufficient_data", "metrics": {"prediction_count": len(scores), "minimum_predictions": minimum, "model_age_days": age_days}, "warnings": warnings + ["insufficient_matured_predictions"]}
    mean = sum(scores) / len(scores)
    successes = [bool(row.get("success")) for row in rows]
    relative_returns = [finite(row.get("relative_return")) for row in rows]
    relative_returns = [value for value in relative_returns if value is not None]
    drawdowns = [finite(row.get("max_drawdown")) for row in rows]
    drawdowns = [value for value in drawdowns if value is not None]
    hit_rate = sum(successes) / len(successes) if successes else 0.0
    baseline = request.get("baseline_metrics", {})
    baseline_hit = finite(baseline.get("hit_rate"))
    baseline_return = finite(baseline.get("benchmark_relative_return"))
    metrics = {"prediction_count": len(scores), "score_mean": mean, "score_min": min(scores), "score_max": max(scores), "hit_rate": hit_rate, "benchmark_relative_return": sum(relative_returns) / len(relative_returns) if relative_returns else 0.0, "max_drawdown": min(drawdowns) if drawdowns else 0.0, "model_age_days": age_days}
    if baseline_hit is not None and hit_rate < baseline_hit - 0.10:
        warnings.append("live_hit_rate_deterioration")
    if baseline_return is not None and metrics["benchmark_relative_return"] < baseline_return - 0.05:
        warnings.append("live_benchmark_return_deterioration")
    if mean < 40 or mean > 60:
        warnings.append("score_distribution_outside_expected_band")
    status = "warning" if warnings else "ok"
    return {"schema_version": 1, "status": status, "metrics": metrics, "warnings": warnings}


def main() -> int:
    try:
        request = read_request()
        operation = sys.argv[1] if len(sys.argv) > 1 else ""
        if operation == "train":
            result = train(request)
        elif operation == "predict":
            result = predict(request)
        elif operation == "drift":
            result = drift(request)
        else:
            raise ValueError("operation must be train, predict, or drift")
        print(json.dumps(result, allow_nan=False, separators=(",", ":")))
        return 0
    except Exception as error:
        print(f"ml adapter failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
