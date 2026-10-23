import unittest

from ci_release_contract import classify, release_allowed


SHA = "a" * 40


def gate(**updates):
    values = {
        "EVENT_NAME": "push",
        "REF_NAME": "refs/heads/master",
        "CANDIDATE_SHA": SHA,
        "CHECKED_OUT_SHA": SHA,
        "DEPLOYABLE": "true",
        "BACKEND_APPLICABLE": "true",
        "FRONTEND_APPLICABLE": "true",
        "VPS_HEALTH_APPLICABLE": "false",
        "CI_WATCHER_APPLICABLE": "false",
        "BACKEND_RESULT": "success",
        "FRONTEND_RESULT": "success",
        "VPS_HEALTH_RESULT": "skipped",
        "CI_WATCHER_RESULT": "skipped",
        "AI_RUNTIME_RESULT": "success",
    }
    values.update(updates)
    return release_allowed(values)[0]


class ScopeTests(unittest.TestCase):
    def test_documentation_only_preserves_no_deploy_semantics(self):
        self.assertEqual(classify(["docs/current/guide.md"]), {
            "backend": False, "frontend": False, "deployable": False, "vps_health": False, "ci_watcher": False,
        })

    def test_backend_change_requires_backend_and_deployment(self):
        scope = classify(["app/app/Services/Example.php"])
        self.assertTrue(scope["backend"])
        self.assertTrue(scope["deployable"])
        self.assertFalse(scope["frontend"])

    def test_frontend_source_requires_frontend_and_deployment(self):
        scope = classify(["app/resources/js/src/App.jsx"])
        self.assertTrue(scope["frontend"])
        self.assertTrue(scope["deployable"])
        self.assertFalse(scope["backend"])

    def test_journey_contract_runs_frontend_ci_without_release(self):
        scope = classify(["docs/user-journeys/01-home.md"])
        self.assertTrue(scope["frontend"])
        self.assertFalse(scope["deployable"])

    def test_vps_health_is_scoped_separately(self):
        scope = classify(["ops/vps-health/checks.py"])
        self.assertTrue(scope["vps_health"])
        self.assertFalse(scope["deployable"])

    def test_ci_watcher_scope_preserves_all_existing_scopes(self):
        scope = classify(["ops/ci-followup-watcher/watcher.py"])
        self.assertEqual(scope, {
            "backend": False, "frontend": False, "deployable": False,
            "vps_health": False, "ci_watcher": True,
        })

    def test_ci_workflow_keeps_original_canonical_scopes(self):
        self.assertEqual(classify([".github/workflows/ci.yml"]), {
            "backend": True, "frontend": True, "deployable": True,
            "vps_health": False, "ci_watcher": False,
        })

    def test_release_gate_implementation_change_qualifies_its_activation(self):
        self.assertTrue(classify(["scripts/ci_release_contract.py"])['deployable'])


class ReleaseGateTests(unittest.TestCase):
    def test_successful_exact_master_candidate_is_accepted(self):
        self.assertTrue(gate())

    def test_inapplicable_frontend_may_be_skipped(self):
        self.assertTrue(gate(**{"FRONTEND_APPLICABLE": "false", "FRONTEND_RESULT": "skipped"}))

    def test_applicable_skip_fails_closed(self):
        self.assertFalse(gate(**{"FRONTEND_RESULT": "skipped"}))
        self.assertFalse(gate(**{"CI_WATCHER_APPLICABLE": "true", "CI_WATCHER_RESULT": "skipped"}))

    def test_applicable_watcher_failure_cancel_and_missing_result_fail_closed(self):
        for result in ("failure", "cancelled", ""):
            with self.subTest(result=result):
                self.assertFalse(gate(**{"CI_WATCHER_APPLICABLE": "true", "CI_WATCHER_RESULT": result}))
        self.assertFalse(gate(**{"CI_WATCHER_APPLICABLE": "true"}))

    def test_failure_cancel_and_missing_results_fail_closed(self):
        for result in ("failure", "cancelled", ""):
            with self.subTest(result=result):
                self.assertFalse(gate(**{"BACKEND_RESULT": result}))
        for result in ("failure", "cancelled", ""):
            with self.subTest(ai_runtime_result=result):
                self.assertFalse(gate(**{"AI_RUNTIME_RESULT": result}))

    def test_wrong_candidate_checkout_sha_fails(self):
        self.assertFalse(gate(**{"CHECKED_OUT_SHA": "b" * 40}))

    def test_pull_request_and_nonmaster_dispatch_fail(self):
        self.assertFalse(gate(**{"EVENT_NAME": "pull_request"}))
        self.assertFalse(gate(**{"REF_NAME": "refs/heads/feature"}))

    def test_docs_only_is_not_a_release(self):
        self.assertFalse(gate(**{"DEPLOYABLE": "false"}))


if __name__ == "__main__":
    unittest.main()
