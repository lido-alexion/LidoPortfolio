import contextlib
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
import install_secret as installer
import transfer_secret as sender

TOKEN = b'github_pat_' + b'fixture_only_never_a_real_credential_' * 2
SHA = 'a' * 40
HOST_KEY = (ROOT / 'stoxla_host_ed25519.pub').read_text()


class EnvironmentInstallationTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.root = Path(self.folder.name)
        self.env = self.root / '.env'
        self.original = b'APP_NAME="StoX app"\nDB_PASSWORD="untouched=value"\nSTOX_GITHUB_ISSUES_ENABLED=false\n'
        self.env.write_bytes(self.original)
        self.env.chmod(0o640)
        self.build = self.root / 'build-info.json'
        self.build.write_text(json.dumps({'commit_sha': SHA}))

    def install(self, token=TOKEN):
        return installer.install(token, SHA, self.env, self.build)

    def assert_unchanged(self):
        self.assertEqual(self.env.read_bytes(), self.original)
        self.assertFalse(list(self.root.glob('.stox-credential-*')))

    def test_install_preserves_every_other_setting_mode_owner_and_group(self):
        before = self.env.stat()
        self.assertEqual(self.install(), 'written')
        self.assertEqual(self.env.read_bytes(), self.original + b'STOX_GITHUB_TOKEN=' + TOKEN + b'\n')
        after = self.env.stat()
        self.assertEqual((stat.S_IMODE(after.st_mode), after.st_uid, after.st_gid),
                         (0o640, before.st_uid, before.st_gid))
        self.assertFalse(list(self.root.glob('.stox-credential-*')))

    def test_repeated_install_is_noop_without_replacing_inode(self):
        self.install()
        before = self.env.stat()
        self.assertEqual(self.install(), 'unchanged')
        self.assertEqual(self.env.stat().st_ino, before.st_ino)
        self.assertEqual(self.env.stat().st_mtime_ns, before.st_mtime_ns)

    def test_rotation_replaces_only_existing_named_entry(self):
        value = b'# STOX_GITHUB_TOKEN=comment\nexport STOX_GITHUB_TOKEN="old-value"\nOTHER_TOKEN=keep\n'
        self.env.write_bytes(value)
        self.install()
        self.assertEqual(self.env.read_bytes(), b'# STOX_GITHUB_TOKEN=comment\nSTOX_GITHUB_TOKEN=' + TOKEN + b'\nOTHER_TOKEN=keep\n')

    def test_crlf_and_missing_final_newline_are_preserved(self):
        self.assertEqual(installer.render_env(b'FIRST=keep\r\nSTOX_GITHUB_TOKEN=old', TOKEN),
                         b'FIRST=keep\r\nSTOX_GITHUB_TOKEN=' + TOKEN)
        self.assertEqual(installer.render_env(b'FIRST=keep', TOKEN),
                         b'FIRST=keep\nSTOX_GITHUB_TOKEN=' + TOKEN + b'\n')

    def test_duplicate_entries_fail_without_modifying_file(self):
        value = b'STOX_GITHUB_TOKEN=first\nSTOX_GITHUB_TOKEN=second\n'
        self.env.write_bytes(value)
        with self.assertRaisesRegex(installer.ProvisionError, '^duplicate_token_entries$'):
            self.install()
        self.assertEqual(self.env.read_bytes(), value)

    def test_bad_input_never_reaches_file(self):
        for token in [b'', b'placeholder', TOKEN + b'\nANOTHER=value', TOKEN + b' ' * 513]:
            with self.subTest(token_kind=len(token)):
                with self.assertRaises(installer.ProvisionError) as result:
                    self.install(token)
                self.assertNotIn(TOKEN.decode(), str(result.exception))
                self.assert_unchanged()

    def test_pasted_surrounding_whitespace_is_removed(self):
        self.install(b' \n' + TOKEN + b'\r\n')
        self.assertIn(b'STOX_GITHUB_TOKEN=' + TOKEN + b'\n', self.env.read_bytes())

    def test_active_build_mismatch_leaves_env_unchanged(self):
        self.build.write_text(json.dumps({'commit_sha': 'b' * 40}))
        with self.assertRaisesRegex(installer.ProvisionError, '^active_build_changed$'):
            self.install()
        self.assert_unchanged()

    def test_env_symlink_is_rejected_without_changing_destination(self):
        destination = self.root / 'real-env'
        self.env.rename(destination)
        self.env.symlink_to(destination)
        with self.assertRaises(OSError):
            self.install()
        self.assertEqual(destination.read_bytes(), self.original)

    def test_world_readable_env_is_not_silently_written(self):
        self.env.chmod(0o644)
        with self.assertRaisesRegex(installer.ProvisionError, '^env_has_world_permissions$'):
            self.install()
        self.assert_unchanged()

    def test_lock_contention_fails_closed(self):
        lock = self.root / '.stox-github-credential.lock'
        lock.touch(mode=0o600)
        with lock.open('r+b') as stream:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(installer.ProvisionError, '^configuration_busy$'):
                self.install()
        self.assert_unchanged()

    def test_lock_symlink_is_rejected(self):
        (self.root / '.stox-github-credential.lock').symlink_to(self.build)
        with self.assertRaises(OSError):
            self.install()
        self.assert_unchanged()

    def test_concurrent_configuration_edit_is_not_lost(self):
        real_read = installer.read_env
        calls = 0
        updated = self.original + b'OTHER_OPERATOR_SETTING=preserve\n'
        def read(path):
            nonlocal calls
            calls += 1
            if calls == 2:
                path.write_bytes(updated)
            return real_read(path)
        with patch.object(installer, 'read_env', side_effect=read):
            with self.assertRaisesRegex(installer.ProvisionError, '^configuration_changed$'):
                self.install()
        self.assertEqual(self.env.read_bytes(), updated)
        self.assertFalse(list(self.root.glob('.stox-credential-*')))

    def test_failed_atomic_replace_cleans_temporary_secret_file(self):
        with patch.object(installer.os, 'replace', side_effect=OSError('fixture failure')):
            with self.assertRaises(OSError):
                self.install()
        self.assert_unchanged()

    def test_release_change_during_install_does_not_commit(self):
        real_check = installer.assert_active_build
        calls = 0
        def check(path, expected):
            nonlocal calls
            calls += 1
            if calls == 2:
                path.write_text(json.dumps({'commit_sha': 'b' * 40}))
            real_check(path, expected)
        with patch.object(installer, 'assert_active_build', side_effect=check):
            with self.assertRaisesRegex(installer.ProvisionError, '^active_build_changed$'):
                self.install()
        self.assert_unchanged()

    def test_cli_never_logs_exception_or_stdin_secret(self):
        output = io.StringIO()
        with patch.object(installer.os, 'geteuid', return_value=1000), \
             patch.object(installer.sys, 'stdin', SimpleNamespace(buffer=io.BytesIO(TOKEN))), \
             patch.object(installer, 'install', side_effect=RuntimeError(TOKEN.decode())), \
             contextlib.redirect_stdout(output):
            self.assertEqual(installer.main(['--expected-active-sha', SHA]), 1)
        self.assertNotIn(TOKEN.decode(), output.getvalue())
        self.assertEqual(json.loads(output.getvalue())['reason'], 'installation_failed')


class TransferTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.key = Path(self.folder.name) / 'deploy-key'
        self.key.write_text('fake identity file')
        self.calls = []

    def fake_run(self, argv, **kwargs):
        self.calls.append((argv, kwargs))
        return b'{"stage":"reporter_credential","status":"written"}' if kwargs.get('data') else b''

    def send(self, **overrides):
        args = dict(token=TOKEN, host='vps.example', user='nitty', sha=SHA, expected_active=SHA,
                    key_path=self.key, host_key=HOST_KEY, installer=ROOT / 'install_secret.py', run=self.fake_run)
        args.update(overrides)
        return sender.transfer(**args)

    def test_token_is_only_on_installer_stdin_and_host_key_is_pinned(self):
        self.assertEqual(self.send(), 'written')
        self.assertEqual(len(self.calls), 3)
        for argv, kwargs in self.calls:
            self.assertNotIn(TOKEN.decode(), ' '.join(argv))
            self.assertIn('StrictHostKeyChecking=yes', argv)
            self.assertIn('HostKeyAlgorithms=ssh-ed25519', argv)
        self.assertEqual([kwargs.get('data') for _, kwargs in self.calls], [None, None, TOKEN])

    def test_ipv6_scp_uses_bracketed_address(self):
        self.send(host='2001:db8::1')
        self.assertIn('nitty@[2001:db8::1]:', self.calls[1][0][-1])

    def test_untrusted_transfer_inputs_never_launch_ssh(self):
        for change in [dict(host='host\nINJECT'), dict(host='fe80::1%zone\nINJECT'), dict(host='-o ProxyCommand=bad'), dict(user='root'),
                       dict(sha='bad'), dict(expected_active='bad'), dict(host_key='ssh-rsa AAAA')]:
            with self.subTest(change=change):
                with self.assertRaises(installer.ProvisionError):
                    self.send(**change)
        self.assertEqual(self.calls, [])

    def test_remote_error_or_unexpected_secret_output_is_not_reflected(self):
        with self.assertRaisesRegex(installer.ProvisionError, '^invalid_install_receipt$'):
            self.send(run=lambda *a, **kw: TOKEN if kw.get('data') else b'')

    def test_child_process_environment_and_args_never_contain_token(self):
        result = subprocess.CompletedProcess([], 0, stdout=b'ok', stderr=TOKEN)
        with patch.dict(os.environ, {'STOX_GITHUB_TOKEN': TOKEN.decode(), 'GITHUB_TOKEN': 'other-token'}), \
             patch.object(sender.subprocess, 'run', return_value=result) as run:
            self.assertEqual(sender.execute(['ssh', 'fixture'], data=TOKEN), b'ok')
        args, kwargs = run.call_args
        self.assertNotIn(TOKEN.decode(), str(args))
        self.assertNotIn(TOKEN.decode(), str(kwargs['env']))
        self.assertEqual(kwargs['input'], TOKEN)

    def test_ssh_failure_suppresses_stderr_and_stdout(self):
        result = subprocess.CompletedProcess([], 1, stdout=TOKEN, stderr=TOKEN)
        with patch.object(sender.subprocess, 'run', return_value=result):
            with self.assertRaisesRegex(installer.ProvisionError, '^ssh_transfer_failed$'):
                sender.execute(['ssh', 'fixture'], data=TOKEN)

    def test_context_refuses_pull_requests_forks_and_mismatched_commits(self):
        base = {'GITHUB_REPOSITORY': 'lido-alexion/LidoPortfolio', 'GITHUB_REF': 'refs/heads/master',
                'GITHUB_EVENT_NAME': 'push', 'GITHUB_SHA': SHA}
        sender.trusted_context(base, SHA)
        for change in [{'GITHUB_EVENT_NAME': 'pull_request'}, {'GITHUB_REF': 'refs/heads/feature'},
                       {'GITHUB_REPOSITORY': 'other/fork'}, {'GITHUB_SHA': 'b' * 40}]:
            with self.assertRaisesRegex(installer.ProvisionError, '^untrusted_context$'):
                sender.trusted_context(base | change, SHA)

    def test_missing_optional_secret_makes_no_network_call(self):
        env = {'GITHUB_REPOSITORY': 'lido-alexion/LidoPortfolio', 'GITHUB_REF': 'refs/heads/master',
               'GITHUB_EVENT_NAME': 'push', 'GITHUB_SHA': SHA}
        output = io.StringIO()
        with patch.dict(os.environ, env, clear=True), patch.object(sender, 'public_build') as public, \
             contextlib.redirect_stdout(output):
            self.assertEqual(sender.main(['--release-sha', SHA]), 0)
        public.assert_not_called()
        self.assertEqual(json.loads(output.getvalue())['status'], 'skipped_secret_not_configured')


class WorkflowContractTests(unittest.TestCase):
    def test_transfer_is_inside_production_deployment_after_master_gate_before_activation(self):
        repo = ROOT.parents[1]
        workflow = (repo / '.github/workflows/deploy-stoxla-production.yml').read_text()
        bridge = workflow.index('python3 ops/issue-reporting/transfer_secret.py')
        self.assertLess(workflow.index('if master_sha != candidate_sha:'), bridge)
        self.assertLess(bridge, workflow.index("'${REMOTE_DIR}/stoxla-deploy-release.sh' '${REMOTE_DIR}/stoxla-release.tgz'"))
        self.assertIn('environment: production', workflow)
        self.assertEqual(workflow.count('STOX_GITHUB_TOKEN: ${{ secrets.STOX_GITHUB_TOKEN }}'), 1)
        self.assertNotIn('${{ secrets.STOX_GITHUB_TOKEN }}', workflow[workflow.index('        run: |', workflow.index('STOX_GITHUB_TOKEN:')):])

    def test_safety_tests_remain_part_of_required_python_gate(self):
        workflow = (ROOT.parents[1] / '.github/workflows/ci.yml').read_text()
        self.assertIn('python3 -m unittest discover -s ops/issue-reporting/tests -v', workflow)
        self.assertIn("needs['ai-runtime'].result == 'success'", workflow)


if __name__ == '__main__':
    unittest.main()
