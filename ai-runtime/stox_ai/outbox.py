"""Durable transport queue only. Laravel remains the audit and spend authority."""
from __future__ import annotations

from contextlib import contextmanager
import json
import logging
import os
import sqlite3
import time
from pathlib import Path
from uuid import uuid4

log = logging.getLogger(__name__)


class DeliveryOutbox:
    def __init__(self, path=None):
        self.path = Path(path or os.getenv('STOX_AI_OUTBOX_PATH', 'var/ai-delivery.sqlite3'))

    @contextmanager
    def connect(self):
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        db = sqlite3.connect(self.path, timeout=5)
        os.chmod(self.path, 0o600)
        db.execute('PRAGMA synchronous=FULL')
        db.execute('CREATE TABLE IF NOT EXISTS deliveries (id TEXT PRIMARY KEY, endpoint TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_attempt REAL NOT NULL DEFAULT 0, error TEXT)')
        try:
            with db:
                yield db
        finally:
            db.close()

    def enqueue(self, endpoint, payload):
        identifier = str(uuid4())
        with self.connect() as db:
            db.execute('INSERT INTO deliveries (id, endpoint, payload) VALUES (?, ?, ?)', (identifier, endpoint, json.dumps(payload)))
        return identifier

    def status(self):
        with self.connect() as db:
            row = db.execute('SELECT COUNT(*), COALESCE(MAX(attempts), 0) FROM deliveries').fetchone()
        return {'pending': row[0], 'max_attempts': row[1]}

    async def drain(self, send):
        with self.connect() as db:
            rows = db.execute('SELECT id, endpoint, payload, attempts FROM deliveries WHERE next_attempt <= ? ORDER BY rowid LIMIT 50', (time.time(),)).fetchall()
        for identifier, endpoint, payload, attempts in rows:
            try:
                await send(endpoint, json.loads(payload))
            except Exception as error:
                log.error('AI delivery pending: envelope=%s attempt=%d category=%s', identifier, attempts + 1, type(error).__name__)
                with self.connect() as db:
                    db.execute('UPDATE deliveries SET attempts=attempts+1, next_attempt=?, error=? WHERE id=?', (time.time() + min(300, 2 ** min(attempts + 1, 9)), type(error).__name__, identifier))
            else:
                with self.connect() as db:
                    db.execute('DELETE FROM deliveries WHERE id=?', (identifier,))
