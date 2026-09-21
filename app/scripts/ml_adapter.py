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
import sys
from datetime import datetime
from typing import Any


def read_request() -> dict[str, Any]:
    payload = json.load(sys.stdin)
    if not isinstance(payload, dict):
        raise ValueError("request must be a JSON object")
    return payload


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


def classification_metrics(y_true: list[int], probabilities: list[float], rows: list[dict[str, Any]], decisions: list[int] | None = None) -> dict[str, Any]:
    from sklearn.metrics import average_precision_score, brier_score_loss, precision_score, recall_score, roc_auc_score

    predicted = decisions if decisions is not None else [1 if value >= 0.5 else 0 for value in probabilities]
    unique = set(y_true)
    metrics: dict[str, Any] = {
        "class_distribution": {"positive": sum(y_true), "negative": len(y_true) - sum(y_true), "rows": len(y_true)},
        "precision": float(precision_score(y_true, predicted, zero_division=0)),
        "recall": float(recall_score(y_true, predicted, zero_division=0)),
        "calibration_error": float(brier_score_loss(y_true, probabilities)),
        "hit_rate": float(sum(a == b for a, b in zip(y_true, predicted)) / len(y_true)) if y_true else 0.0,
    }
    if len(unique) < 2:
        raise ValueError("evaluation set contains one class")
    metrics["roc_auc"] = float(roc_auc_score(y_true, probabilities))
    metrics["pr_auc"] = float(average_precision_score(y_true, probabilities))
    positive_rows = [row for row, prediction in zip(rows, predicted) if prediction == 1]
    returns = [finite(row.get("relative_return")) for row in positive_rows]
    drawdowns = [finite(row.get("max_drawdown")) for row in positive_rows]
    returns = [value for value in returns if value is not None]
    drawdowns = [value for value in drawdowns if value is not None]
    metrics["benchmark_relative_return"] = sum(returns) / len(returns) if returns else 0.0
    metrics["max_drawdown"] = min(drawdowns) if drawdowns else 0.0
    metrics["deterministic_baseline_delta"] = 0.0
    return metrics


def train(request: dict[str, Any]) -> dict[str, Any]:
    from sklearn.linear_model import LogisticRegression

    rows = request.get("rows")
    if not isinstance(rows, list) or len(rows) < 12:
        raise ValueError("training dataset is too small")
    numeric = list(request.get("numeric_features", []))
    categorical = list(request.get("categorical_features", []))
    partitions = request.get("partitions", {})
    train_rows = [row for row in rows if row.get("partition") == "train"]
    validation_rows = [row for row in rows if row.get("partition") == "validation"]
    test_rows = [row for row in rows if row.get("partition") == "test"]
    if not train_rows or not validation_rows or not test_rows:
        raise ValueError("chronological train/validation/test partitions are required")
    y_train = [int(row["label"]) for row in train_rows]
    if len(set(y_train)) < 2:
        raise ValueError("training set contains one class")
    x_train, feature_names, state = prepare(train_rows, numeric, categorical)
    x_validation, _, _ = prepare(validation_rows, numeric, categorical, state)
    x_test, _, _ = prepare(test_rows, numeric, categorical, state)
    model = LogisticRegression(class_weight="balanced", max_iter=1000, random_state=int(request.get("seed", 7047)))
    model.fit(x_train, y_train)
    validation_probabilities = model.predict_proba(x_validation)[:, 1].tolist()
    test_probabilities = model.predict_proba(x_test)[:, 1].tolist()
    validation_metrics = classification_metrics([int(row["label"]) for row in validation_rows], validation_probabilities, validation_rows)
    test_metrics = classification_metrics([int(row["label"]) for row in test_rows], test_probabilities, test_rows)

    naive_probabilities = [sum(y_train) / len(y_train)] * len(test_rows)
    naive_metrics = classification_metrics([int(row["label"]) for row in test_rows], naive_probabilities, test_rows)
    deterministic_rows = request.get("deterministic_baseline")
    if not isinstance(deterministic_rows, list) or len(deterministic_rows) != len(test_rows):
        raise ValueError("deterministic StoX baseline is missing or not aligned to the test partition")
    deterministic_probabilities = [float(item["probability"]) for item in deterministic_rows]
    deterministic_decisions = [1 if bool(item.get("positive_decision")) else 0 for item in deterministic_rows]
    deterministic_metrics = classification_metrics([int(row["label"]) for row in test_rows], deterministic_probabilities, test_rows, deterministic_decisions)
    test_metrics["deterministic_baseline_delta"] = test_metrics["benchmark_relative_return"] - deterministic_metrics["benchmark_relative_return"]
    metadata = {
        "format": "stox-v7-logistic",
        "feature_names": feature_names,
        "numeric_features": numeric,
        "categorical_features": categorical,
        "preprocessing": state,
        "partitions": partitions,
        "runtime": {"python": sys.version.split()[0], "sklearn": __import__("sklearn").__version__},
        "seed": int(request.get("seed", 7047)),
    }
    artifact_path = request.get("artifact_path")
    if not isinstance(artifact_path, str) or not artifact_path:
        raise ValueError("artifact_path is required")
    os.makedirs(os.path.dirname(artifact_path), exist_ok=True)
    import joblib

    joblib.dump({"model": model, **metadata}, artifact_path, compress=3)
    digest = hashlib.sha256(open(artifact_path, "rb").read()).hexdigest()
    metrics = dict(test_metrics)
    metrics["validation"] = validation_metrics
    metrics["test"] = test_metrics
    metrics["train_rows"] = len(train_rows)
    metrics["validation_rows"] = len(validation_rows)
    metrics["test_rows"] = len(test_rows)
    return {"schema_version": 1, "artifact_sha256": digest, "metrics": metrics, "baselines": {"naive": naive_metrics, "deterministic_stox": deterministic_metrics}, "metadata": metadata}


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
    probability = float(artifact["model"].predict_proba(vectors)[0, 1])
    contributions = []
    for name, value, coefficient in zip(names, vectors[0], artifact["model"].coef_[0].tolist()):
        contribution = float(value * coefficient)
        if contribution != 0:
            contributions.append({"feature": name, "value": value, "coefficient": coefficient, "contribution": contribution, "direction": "positive" if contribution > 0 else "negative"})
    contributions.sort(key=lambda item: abs(item["contribution"]), reverse=True)
    return {"schema_version": 1, "score": round(probability * 100, 4), "confidence": round(max(probability, 1 - probability), 4), "contributions": contributions[:20], "artifact_sha256": digest}


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
