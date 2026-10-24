#!/usr/bin/env python3
"""Transfer an environment secret over pinned SSH without logging its value."""
from __future__ import annotations

import argparse
import base64
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.request import urlopen

from install_secret import ProvisionError, validate_token

ROOT = Path(__file__).resolve().parent


def trusted_context(values: dict[str, str], sha: str) -> None:
    if (values.get('GITHUB_REPOSITORY') != 'lido-alexion/LidoPortfolio'
            or values.get('GITHUB_REF') != 'refs/heads/master'
            or values.get('GITHUB_EVENT_NAME') not in {'push', 'workflow_dispatch'}
            or not re.fullmatch(r'[0-9a-f]{40}', sha)
            or values.get('GITHUB_SHA') != sha):
        raise ProvisionError('untrusted_context')


def validate_host(host: str) -> str:
    if not re.fullmatch(r'[A-Za-z0-9.:-]+', host):
        raise ProvisionError('invalid_ssh_host')
    try:
        ipaddress.ip_address(host)
        return host
    except ValueError:
        if not re.fullmatch(r'(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?', host):
            raise ProvisionError('invalid_ssh_host') from None
        return host


def public_build() -> str:
    with urlopen('https://stoxla.in/api/build-info', timeout=15) as response:
        data = json.loads(response.read(16385))
    sha = data.get('data', {}).get('commit_sha', '')
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        raise ProvisionError('invalid_public_build')
    return sha


def execute(argv: list[str], *, data: bytes | None = None) -> bytes:
    # Credentials never enter child environments or command arguments.
    result = subprocess.run(argv, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            env={'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LC_ALL': 'C'},
                            timeout=60, check=False)
    if result.returncode != 0:
        raise ProvisionError('ssh_transfer_failed')
    return result.stdout


def transfer(token: bytes, host: str, user: str, sha: str, expected_active: str,
             key_path: Path, host_key: str, installer: Path, run=execute) -> str:
    token = validate_token(token)
    host = validate_host(host)
    if user != 'nitty' or not re.fullmatch(r'[0-9a-f]{40}', sha) or not re.fullmatch(r'[0-9a-f]{40}', expected_active):
        raise ProvisionError('invalid_transfer_identity')
    fields = host_key.strip().split()
    if len(fields) != 2 or fields[0] != 'ssh-ed25519':
        raise ProvisionError('invalid_host_key')
    try:
        base64.b64decode(fields[1], validate=True)
    except ValueError:
        raise ProvisionError('invalid_host_key') from None
    if not key_path.is_file():
        raise ProvisionError('ssh_identity_missing')
    remote_dir = '/home/nitty/.stoxla-deploy/issue-reporter-credential/' + sha
    with tempfile.TemporaryDirectory(prefix='stox-reporter-ssh-') as folder:
        known_hosts = Path(folder) / 'known_hosts'
        known_hosts.write_text(host + ' ' + ' '.join(fields) + '\n')
        options = ['-F', '/dev/null', '-i', str(key_path), '-o', 'IdentitiesOnly=yes',
                   '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                   '-o', 'UserKnownHostsFile=' + str(known_hosts),
                   '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'HostKeyAlgorithms=ssh-ed25519',
                   '-o', 'ConnectTimeout=15', '-o', 'LogLevel=ERROR']
        target = user + '@' + host
        run(['ssh', *options, target, 'umask 077; mkdir -p ' + remote_dir])
        scp_target = user + '@' + ('[' + host + ']' if ':' in host else host)
        run(['scp', *options, str(installer), scp_target + ':' + remote_dir + '/install_secret.py'])
        output = run(['ssh', *options, target, '/usr/bin/python3 ' + remote_dir
                      + '/install_secret.py --expected-active-sha ' + expected_active], data=token)
        if len(output) > 1024:
            raise ProvisionError('invalid_install_receipt')
        try:
            receipt = json.loads(output)
        except (ValueError, TypeError):
            raise ProvisionError('invalid_install_receipt') from None
        if receipt.get('stage') != 'reporter_credential' or receipt.get('status') not in {'written', 'unchanged'}:
            raise ProvisionError('installation_not_confirmed')
        return receipt['status']


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release-sha', required=True)
    args = parser.parse_args(argv)
    try:
        trusted_context(dict(os.environ), args.release_sha)
        value = os.environ.get('STOX_GITHUB_TOKEN', '')
        if not value:
            print(json.dumps({'stage': 'reporter_credential', 'status': 'skipped_secret_not_configured'}))
            return 0
        status = transfer(validate_token(value.encode()), os.environ.get('STOXLA_HOST', ''),
                          os.environ.get('STOXLA_SSH_USER', ''), args.release_sha, public_build(),
                          Path.home() / '.ssh/stoxla_deploy_key',
                          (ROOT / 'stoxla_host_ed25519.pub').read_text(), ROOT / 'install_secret.py')
        print(json.dumps({'stage': 'reporter_credential', 'status': status}))
        return 0
    except ProvisionError as error:
        print(json.dumps({'stage': 'reporter_credential', 'status': 'failed', 'reason': str(error)}))
    except Exception:
        print(json.dumps({'stage': 'reporter_credential', 'status': 'failed', 'reason': 'transfer_failed'}))
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
