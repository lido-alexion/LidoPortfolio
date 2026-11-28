import unittest

from ci_release_contract import classify, readiness_allowed, release_allowed


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

    def test_authenticated_production_smoke_and_direct_dependencies_are_deployable(self):
        for path in (
            "app/tests/e2e/authenticatedProductionSmoke.mjs",
            "app/tests/e2e/productionSmokeDiagnostics.js",
            "app/tests/e2e/productionSmokePolicy.js",
            "app/tests/e2e/productionSmokeSelectors.js",
            "app/tests/e2e/production-smoke.spec.js",
            "app/tests/e2e/kite-header-connect.spec.js",
        ):
            with self.subTest(path=path):
                scope = classify([path])
                self.assertTrue(scope["frontend"])
                self.assertTrue(scope["deployable"])

    def test_generic_e2e_tests_remain_frontend_only(self):
        scope = classify(["app/tests/e2e/example.spec.js"])
        self.assertTrue(scope["frontend"])
        self.assertFalse(scope["deployable"])


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


class ReadinessGateTests(unittest.TestCase):
    def readiness(self, **updates):
        values = {
            "EVENT_NAME": "pull_request", "REF_NAME": "refs/pull/12/merge",
            "PR_ACTION": "ready_for_review", "PR_DRAFT": "false",
            "EVENT_HEAD_SHA": "b" * 40, "CURRENT_HEAD_SHA": "b" * 40,
            "CURRENT_BASE_SHA": "c" * 40, "CURRENT_BASE_REF": "master",
            "CURRENT_BASE_REPO": "lido-alexion/LidoPortfolio",
            "CURRENT_PR_NUMBER": "12", "PR_NUMBER": "12",
            "CURRENT_PR_STATE": "open", "CURRENT_PR_DRAFT": "false",
            "CHECKED_OUT_PARENTS": "c" * 40 + " " + "b" * 40,
            "WORKFLOW_SHA": SHA, "CANDIDATE_SHA": SHA, "CHECKED_OUT_SHA": SHA,
            "CHANGES_RESULT": "success",
            "GITHUB_REPOSITORY": "lido-alexion/LidoPortfolio",
            "DEPLOYABLE": "true", "BACKEND_APPLICABLE": "true",
            "FRONTEND_APPLICABLE": "true", "VPS_HEALTH_APPLICABLE": "false",
            "CI_WATCHER_APPLICABLE": "false", "BACKEND_RESULT": "success",
            "FRONTEND_RESULT": "success", "VPS_HEALTH_RESULT": "skipped",
            "CI_WATCHER_RESULT": "skipped", "AI_RUNTIME_RESULT": "success",
            "PACKAGE_RESULT": "skipped",
        }
        values.update(updates)
        return readiness_allowed(values)[0]

    def test_ready_and_synchronize_accept_current_evidence(self):
        self.assertTrue(self.readiness())
        self.assertTrue(self.readiness(PR_ACTION="synchronize"))

    def test_same_head_draft_to_ready_transition_requires_fresh_ready_evidence(self):
        draft_head = "b" * 40
        self.assertFalse(self.readiness(PR_DRAFT="true", CURRENT_PR_DRAFT="true"))
        self.assertTrue(self.readiness(PR_ACTION="ready_for_review", PR_DRAFT="false",
                                       EVENT_HEAD_SHA=draft_head, CURRENT_HEAD_SHA=draft_head))

    def test_draft_and_converted_to_draft_never_report_ready(self):
        self.assertFalse(self.readiness(PR_DRAFT="true"))
        self.assertFalse(self.readiness(PR_ACTION="converted_to_draft"))

    def test_opened_reopened_are_supported_and_manual_master_dispatch_is_supported(self):
        self.assertTrue(self.readiness(PR_ACTION="opened"))
        self.assertTrue(self.readiness(PR_ACTION="reopened"))
        master = {
            "EVENT_NAME": "workflow_dispatch", "REF_NAME": "refs/heads/master",
            "CANDIDATE_SHA": SHA, "CHECKED_OUT_SHA": SHA, "WORKFLOW_SHA": SHA,
            "DEPLOYABLE": "true", "BACKEND_APPLICABLE": "true",
            "FRONTEND_APPLICABLE": "true", "VPS_HEALTH_APPLICABLE": "false",
            "CI_WATCHER_APPLICABLE": "false", "BACKEND_RESULT": "success",
            "FRONTEND_RESULT": "success", "VPS_HEALTH_RESULT": "skipped",
            "CI_WATCHER_RESULT": "skipped", "AI_RUNTIME_RESULT": "success",
            "PACKAGE_RESULT": "success",
            "CHANGES_RESULT": "success",
        }
        self.assertTrue(readiness_allowed(master)[0])
        self.assertTrue(readiness_allowed({**master, "EVENT_NAME": "push"})[0])

    def test_stale_or_mismatched_head_merge_and_checkout_fail(self):
        for update in ({"CURRENT_HEAD_SHA": "d" * 40},
                       {"CURRENT_BASE_SHA": "d" * 40},
                       {"CHECKED_OUT_SHA": "c" * 40},
                       {"CHECKED_OUT_PARENTS": "c" * 40 + " " + "d" * 40}):
            with self.subTest(update=update):
                self.assertFalse(self.readiness(**update))

    def test_invalid_candidate_or_pr_ref_and_missing_packaging_evidence_fail(self):
        self.assertFalse(self.readiness(REF_NAME="refs/pull/13/merge"))
        self.assertFalse(self.readiness(PR_NUMBER="", CURRENT_PR_NUMBER=""))
        self.assertFalse(self.readiness(CANDIDATE_SHA="x", CHECKED_OUT_SHA="x", WORKFLOW_SHA="x"))
        self.assertFalse(self.readiness(PACKAGE_RESULT="not_required"))

    def test_changed_scope_check_skips_fail_and_master_packaging_is_mandatory(self):
        self.assertFalse(self.readiness(**{"BACKEND_RESULT": "skipped"}))
        self.assertFalse(self.readiness(**{"DEPLOYABLE": ""}))
        master = {
            "EVENT_NAME": "push", "REF_NAME": "refs/heads/master",
            "CANDIDATE_SHA": SHA, "CHECKED_OUT_SHA": SHA, "WORKFLOW_SHA": SHA,
            "DEPLOYABLE": "true", "BACKEND_APPLICABLE": "false",
            "FRONTEND_APPLICABLE": "false", "VPS_HEALTH_APPLICABLE": "false",
            "CI_WATCHER_APPLICABLE": "false", "BACKEND_RESULT": "skipped",
            "FRONTEND_RESULT": "skipped", "VPS_HEALTH_RESULT": "skipped",
            "CI_WATCHER_RESULT": "skipped", "AI_RUNTIME_RESULT": "success",
            "PACKAGE_RESULT": "failure",
        }
        self.assertFalse(readiness_allowed(master)[0])
        self.assertFalse(self.readiness(**{"AI_RUNTIME_RESULT": "cancelled"}))

    def test_stale_action_and_untrusted_ref_fail(self):
        self.assertFalse(self.readiness(PR_ACTION="closed"))
        self.assertFalse(self.readiness(REF_NAME="refs/heads/feature"))

    def test_live_pull_request_identity_state_draft_and_target_are_required(self):
        for update in ({"CURRENT_PR_NUMBER": "13"}, {"CURRENT_PR_STATE": "closed"},
                       {"CURRENT_PR_DRAFT": "true"}, {"CURRENT_BASE_REF": "develop"},
                       {"CURRENT_BASE_REPO": "someone/else"}):
            with self.subTest(update=update):
                self.assertFalse(self.readiness(**update))

    def test_non_deployable_changes_still_require_all_applicable_checks_and_valid_flags(self):
        base = {"DEPLOYABLE": "false", "BACKEND_APPLICABLE": "false", "BACKEND_RESULT": "skipped",
                "FRONTEND_APPLICABLE": "true", "FRONTEND_RESULT": "success",
                "VPS_HEALTH_APPLICABLE": "true", "VPS_HEALTH_RESULT": "success",
                "CI_WATCHER_APPLICABLE": "false", "CI_WATCHER_RESULT": "skipped"}
        self.assertTrue(self.readiness(**base))
        self.assertFalse(self.readiness(**{**base, "FRONTEND_RESULT": "failure"}))
        self.assertFalse(self.readiness(**{**base, "VPS_HEALTH_RESULT": "cancelled"}))
        self.assertFalse(self.readiness(**{**base, "FRONTEND_APPLICABLE": "maybe"}))
        self.assertFalse(self.readiness(**{**base, "CI_WATCHER_APPLICABLE": ""}))
        self.assertFalse(self.readiness(**{**base, "DEPLOYABLE": "maybe"}))

    def test_docs_only_master_still_requires_ai_runtime_and_skipped_package(self):
        docs = {
            "EVENT_NAME": "push", "REF_NAME": "refs/heads/master", "CANDIDATE_SHA": SHA,
            "CHECKED_OUT_SHA": SHA, "WORKFLOW_SHA": SHA, "DEPLOYABLE": "false",
            "BACKEND_APPLICABLE": "false", "FRONTEND_APPLICABLE": "false",
            "VPS_HEALTH_APPLICABLE": "false", "CI_WATCHER_APPLICABLE": "false",
            "BACKEND_RESULT": "skipped", "FRONTEND_RESULT": "skipped",
            "VPS_HEALTH_RESULT": "skipped", "CI_WATCHER_RESULT": "skipped",
            "AI_RUNTIME_RESULT": "success", "PACKAGE_RESULT": "skipped",
            "CHANGES_RESULT": "success",
        }
        self.assertTrue(readiness_allowed(docs)[0])
        self.assertFalse(readiness_allowed({**docs, "AI_RUNTIME_RESULT": "failure"})[0])
        self.assertFalse(readiness_allowed({**docs, "CHANGES_RESULT": "cancelled"})[0])


if __name__ == "__main__":
    unittest.main()
