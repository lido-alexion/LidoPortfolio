#!/usr/bin/env python3
"""Install one credential from stdin; never enable reporting or print secrets."""
from __future__ import annotations

import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile

ENV_PATH = Path('/var/www/stoxla/shared/.env')
BUILD_PATH = Path('/var/www/stoxla/current/bootstrap/build-info.json')
TOKEN_PATTERN = re.compile(rb'github_pat_[A-Za-z0-9_]{20,240}')
ENTRY = re.compile(rb'[ \t]*(?:export[ \t]+)?STOX_GITHUB_TOKEN[ \t]*=.*')


class ProvisionError(Exception):
    """Only fixed, non-sensitive error codes may reach the caller."""


def validate_token(value: bytes) -> bytes:
    if len(value) > 512:
        raise ProvisionError('invalid_token_format')
    token = value.strip(b' \t\r\n')
    if not TOKEN_PATTERN.fullmatch(token):
        raise ProvisionError('invalid_token_format')
    return token


def render_env(original: bytes, token: bytes) -> bytes:
    token = validate_token(token)
    lines = original.splitlines(keepends=True)
    matches = [i for i, line in enumerate(lines) if ENTRY.fullmatch(line.rstrip(b'\r\n'))]
    if len(matches) > 1:
        raise ProvisionError('duplicate_token_entries')
    newline = b'\r\n' if b'\r\n' in original else b'\n'
    replacement = b'STOX_GITHUB_TOKEN=' + token
    if matches:
        i = matches[0]
        ending = b'\r\n' if lines[i].endswith(b'\r\n') else b'\n' if lines[i].endswith(b'\n') else b''
        lines[i] = replacement + ending
        return b''.join(lines)
    separator = b'' if not original or original.endswith(b'\n') else newline
    return original + separator + replacement + newline


def read_env(path: Path) -> tuple[bytes, os.stat_result]:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid():
            raise ProvisionError('unexpected_env_owner_or_type')
        if stat.S_IMODE(info.st_mode) & 0o007:
            raise ProvisionError('env_has_world_permissions')
        content = source.read(1_048_577)
        if len(content) > 1_048_576:
            raise ProvisionError('env_too_large')
        return content, info


def assert_active_build(path: Path, expected: str) -> None:
    if not re.fullmatch(r'[0-9a-f]{40}', expected):
        raise ProvisionError('invalid_expected_build')
    if json.loads(path.read_text()).get('commit_sha') != expected:
        raise ProvisionError('active_build_changed')


def install(token: bytes, expected: str, env_path: Path = ENV_PATH,
            build_path: Path = BUILD_PATH) -> str:
    token = validate_token(token)
    assert_active_build(build_path, expected)
    lock_path = env_path.parent / '.stox-github-credential.lock'
    lock_fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    temporary: str | None = None
    with os.fdopen(lock_fd, 'r+b') as lock:
        info = os.fstat(lock.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o600:
            raise ProvisionError('unexpected_lock_owner_or_mode')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ProvisionError('configuration_busy') from None
        original, before = read_env(env_path)
        updated = render_env(original, token)
        if updated == original:
            return 'unchanged'
        try:
            fd, temporary = tempfile.mkstemp(prefix='.stox-credential-', dir=env_path.parent)
            with os.fdopen(fd, 'wb') as destination:
                destination.write(updated)
                destination.flush()
                os.fchown(destination.fileno(), -1, before.st_gid)
                os.fchmod(destination.fileno(), stat.S_IMODE(before.st_mode))
                os.fsync(destination.fileno())
            current, after = read_env(env_path)
            identity = lambda s: (s.st_dev, s.st_ino, s.st_mtime_ns, s.st_mode, s.st_uid, s.st_gid)
            if current != original or identity(before) != identity(after):
                raise ProvisionError('configuration_changed')
            assert_active_build(build_path, expected)
            os.replace(temporary, env_path)
            temporary = None
            directory = os.open(env_path.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
            return 'written'
        finally:
            if temporary is not None:
                os.unlink(temporary)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--expected-active-sha', required=True)
    args = parser.parse_args(argv)
    try:
        if os.geteuid() != 1000:
            raise ProvisionError('unexpected_operator_uid')
        token = validate_token(sys.stdin.buffer.read(513))
        status = install(token, args.expected_active_sha)
        print(json.dumps({'stage': 'reporter_credential', 'status': status}))
        return 0
    except ProvisionError as error:
        print(json.dumps({'stage': 'reporter_credential', 'status': 'failed', 'reason': str(error)}))
    except Exception:
        # Paths, dotenv contents and exception text must never reach CI logs.
        print(json.dumps({'stage': 'reporter_credential', 'status': 'failed', 'reason': 'installation_failed'}))
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
