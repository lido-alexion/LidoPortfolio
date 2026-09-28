"""FEAT-063 collector process entry."""

from __future__ import annotations

import os
from pathlib import Path


def _path(name: str, default: str) -> Path:
    return Path(os.environ.get(name, default)).expanduser()


def main() -> int:
    from collector.collector_app import CollectorApp

    app = CollectorApp(
        command_file=_path("MICROSTRUCTURE_COMMAND_FILE", "storage/app/microstructure/collector-command.json"),
        heartbeat_file=_path("MICROSTRUCTURE_HEARTBEAT_FILE", "storage/app/microstructure/collector-heartbeat.json"),
        data_root=_path("MICROSTRUCTURE_DATA_ROOT", "storage/app/microstructure"),
    )
    app.run_forever()
    return 0
