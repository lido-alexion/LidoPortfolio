"""Validated, observable configuration snapshots for the CI watcher."""
from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def validate_config(conf: Any) -> dict[str, Any]:
    if not isinstance(conf, dict):
        raise ValueError("configuration must be an object")
    if conf.get("repository") != "lido-alexion/LidoPortfolio":
        raise ValueError("repository must be the explicit public StoX repository")
    poll = conf.get("poll_seconds", 120)
    if type(poll) is not int or poll < 120:
        raise ValueError("poll interval must be an integer of at least 120 seconds")
    targets = conf.get("targets")
    if not isinstance(targets, list) or not targets:
        raise ValueError("explicit target allowlist required")
    if any(not isinstance(t, dict) for t in targets):
        raise ValueError("each target must be an object")
    allowed = {"pull_request", "nightly_master"}
    if any(not isinstance(t.get("kind"), str) or t.get("kind") not in allowed for t in targets):
        raise ValueError("unknown target kind")
    if sum(t.get("kind") == "nightly_master" for t in targets) != 1:
        raise ValueError("exactly one current-master nightly target required")
    names = [t.get("name") for t in targets]
    if any(not isinstance(n, str) or not n for n in names) or len(names) != len(set(names)):
        raise ValueError("target names must be nonempty and unique")
    for target in targets:
        expected = "ci.yml" if target["kind"] == "pull_request" else "journey-nightly.yml"
        if target.get("workflow") != expected:
            raise ValueError("target workflow does not match its permitted kind")
        if target["kind"] == "pull_request" and (type(target.get("number")) is not int or target["number"] < 1):
            raise ValueError("PR target number must be a positive integer")
        if not isinstance(target.get("worktree", ""), str):
            raise ValueError("target worktree must be a string")
        for flag in ("enabled", "report_only"):
            if flag in target and type(target[flag]) is not bool:
                raise ValueError(f"target {flag} must be boolean")
    prs = [t for t in targets if t.get("kind") == "pull_request"]
    if not any(t.get("number") == 240 for t in prs):
        raise ValueError("PR 240 must remain explicitly allowlisted")
    numbers = [t["number"] for t in prs]
    if len(numbers) != len(set(numbers)):
        raise ValueError("PR allowlist numbers must be unique")
    return conf


def read_snapshot(path: Path) -> tuple[dict[str, Any], str]:
    """Parse, validate, and hash exact bytes. Errors never include file content."""
    raw = path.read_bytes()
    try:
        conf = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ValueError("configuration is not valid UTF-8 JSON") from exc
    return validate_config(conf), hashlib.sha256(raw).hexdigest()


def observed_revision(path: Path) -> str | None:
    try:
        return hashlib.sha256(path.read_bytes()).hexdigest()
    except OSError:
        return None


def loaded_record(conf: dict[str, Any], revision: str, loaded_at: str | None = None) -> dict[str, Any]:
    return {
        "revision": revision,
        "loaded_at": loaded_at or datetime.now(timezone.utc).isoformat(),
        "targets": [
            {
                "name": t["name"], "kind": t["kind"], "number": t.get("number"), "workflow": t["workflow"],
                "configured": bool(t.get("worktree", "").strip()),
                "enabled": bool(t.get("enabled", True)) and bool(t.get("worktree", "").strip()),
                "report_only": bool(t.get("report_only", False)),
            }
            for t in conf["targets"]
        ],
    }
