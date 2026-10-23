#!/usr/bin/env python3
"""Sanitized, credential-free diagnostics for the production SSH key scan."""

from __future__ import annotations

import os
import socket
import subprocess
import sys
import tempfile
from pathlib import Path


MAX_ADDRESSES = 8
CONNECT_TIMEOUT_SECONDS = 0.15
DIAGNOSTIC_WALL_TIMEOUT_SECONDS = 2.0


def _family_name(family: int) -> str:
    if family == socket.AF_INET:
        return "ipv4"
    if family == socket.AF_INET6:
        return "ipv6"
    return "other"


def diagnose(host: str) -> int:
    """Report DNS and bounded TCP/22 outcomes without exposing host or addresses."""
    try:
        answers = socket.getaddrinfo(host, 22, type=socket.SOCK_STREAM)
    except socket.gaierror as error:
        # Use only the stable error category. Resolver text may include the name.
        kind = "temporary" if error.errno == getattr(socket, "EAI_AGAIN", object()) else "failed"
        print(f"runner_diagnostic stage=dns status={kind} family=none address_count=0")
        return 0
    except OSError:
        print("runner_diagnostic stage=dns status=failed family=none address_count=0")
        return 0

    unique: list[tuple[int, int, int, tuple]] = []
    seen: set[tuple] = set()
    for family, socktype, proto, _canonname, sockaddr in answers:
        identity = (family, socktype, proto, sockaddr)
        if identity not in seen:
            seen.add(identity)
            unique.append((family, socktype, proto, sockaddr))

    families = sorted({_family_name(item[0]) for item in unique})
    family_label = ",".join(families) if families else "none"
    print(f"runner_diagnostic stage=dns status=ok family={family_label} address_count={len(unique)}")
    if not unique:
        print("runner_diagnostic stage=tcp22 status=unavailable family=none address_count=0")
        return 0

    # Probe at least one address per available family before filling the cap.
    probes: list[tuple[int, int, int, tuple]] = []
    for family in sorted({item[0] for item in unique}):
        probes.append(next(item for item in unique if item[0] == family))
    for item in unique:
        if len(probes) >= MAX_ADDRESSES:
            break
        if item not in probes:
            probes.append(item)

    successes: set[str] = set()
    failures: set[str] = set()
    for family, socktype, proto, sockaddr in probes:
        name = _family_name(family)
        try:
            with socket.socket(family, socktype, proto) as connection:
                connection.settimeout(CONNECT_TIMEOUT_SECONDS)
                connection.connect(sockaddr)
            successes.add(name)
        except OSError:
            failures.add(name)

    if successes and failures:
        status = "partial"
    elif successes:
        status = "reachable"
    else:
        status = "unreachable"
    observed_families = ",".join(sorted(successes | failures)) or "none"
    reachable_families = ",".join(sorted(successes)) or "none"
    failed_families = ",".join(sorted(failures)) or "none"
    print(
        f"runner_diagnostic stage=tcp22 status={status} family={observed_families} "
        f"reachable_family={reachable_families} failed_family={failed_families} "
        f"address_count={len(unique)} probe_count={len(probes)}"
    )
    return 0


def run_diagnostics(host: str) -> None:
    """Run supplemental probes in a killable process with a global wall budget."""
    worker_env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "STOXLA_HOST": host,
    }
    try:
        result = subprocess.run(
            [sys.executable, str(Path(__file__).resolve()), "--diagnostic-worker"],
            env=worker_env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
            timeout=DIAGNOSTIC_WALL_TIMEOUT_SECONDS,
            check=False,
        )
    except subprocess.TimeoutExpired:
        print("runner_diagnostic stage=diagnostic status=timeout exit_kind=deadline")
        return
    except OSError:
        print("runner_diagnostic stage=diagnostic status=failed exit_kind=unavailable")
        return

    if result.returncode != 0:
        print("runner_diagnostic stage=diagnostic status=failed exit_kind=nonzero")
    elif result.stdout:
        # Worker output is restricted to the helper's sanitized stage records.
        print(result.stdout, end="" if result.stdout.endswith("\n") else "\n")


def run_keyscan(host: str, known_hosts: Path) -> int:
    """Run the normal ssh-keyscan and preserve its output and exit status."""
    known_hosts.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    # ssh-keyscan stdout may contain key material and stderr may contain endpoints;
    # keep both out of logs, then append stdout exactly as the original command did.
    with known_hosts.open("ab") as destination, tempfile.TemporaryFile(mode="w+b") as output:
        try:
            result = subprocess.run(
                ["ssh-keyscan", "-H", host], stdout=output, stderr=subprocess.DEVNULL,
                check=False,
            )
            code = result.returncode
        except OSError:
            print("runner_diagnostic stage=hostkey status=failed exit_kind=unavailable")
            return 127
        output.seek(0)
        while chunk := output.read(65536):
            destination.write(chunk)

    kind = "success" if code == 0 else "nonzero"
    print(f"runner_diagnostic stage=hostkey status={'ok' if code == 0 else 'failed'} exit_kind={kind}")
    return code


def main(argv: list[str] | None = None) -> int:
    arguments = sys.argv[1:] if argv is None else argv
    host = os.environ.get("STOXLA_HOST", "")
    if arguments == ["--diagnostic-worker"]:
        try:
            diagnose(host)
        except Exception:
            # Exception text can contain host or resolver details.
            print("runner_diagnostic stage=diagnostic status=failed exit_kind=exception")
        return 0
    if not host:
        print("runner_diagnostic stage=configuration status=missing_host")
        return 2
    run_diagnostics(host)
    known_hosts = Path(os.environ.get("STOXLA_KNOWN_HOSTS", str(Path.home() / ".ssh/known_hosts")))
    return run_keyscan(host, known_hosts)


if __name__ == "__main__":
    raise SystemExit(main())
