from pathlib import Path
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW_PATH = ROOT / ".github/workflows/ci.yml"
DEPLOY_WORKFLOW_PATH = ROOT / ".github/workflows/deploy-stoxla-production.yml"


class UniqueKeyLoader(yaml.BaseLoader):
    def construct_mapping(self, node, deep=False):
        mapping = {}
        for key_node, value_node in node.value:
            key = self.construct_object(key_node, deep=deep)
            if key in mapping:
                raise ValueError(f"duplicate YAML key: {key}")
            mapping[key] = self.construct_object(value_node, deep=deep)
        return mapping


WORKFLOW = yaml.load(WORKFLOW_PATH.read_text(), Loader=UniqueKeyLoader)
DEPLOY_WORKFLOW = yaml.load(DEPLOY_WORKFLOW_PATH.read_text(), Loader=UniqueKeyLoader)
JOBS = WORKFLOW["jobs"]


class WorkflowGraphTests(unittest.TestCase):
    def test_yaml_parses_and_every_dependency_exists(self):
        for job_id, job in JOBS.items():
            needs = job.get("needs", [])
            if isinstance(needs, str):
                needs = [needs]
            for dependency in needs:
                self.assertIn(dependency, JOBS, f"{job_id} depends on missing job {dependency}")

    def test_canonical_check_names_remain_stable(self):
        self.assertEqual(JOBS["backend"]["name"], "PHPUnit (PHP 8.4)")
        self.assertEqual(JOBS["frontend"]["name"], "Frontend tests and production build (Node 22)")
        self.assertEqual(JOBS["vps-health"]["name"], "VPS health monitor tests (Python 3.12)")
        self.assertEqual(JOBS["ai-runtime"]["name"], "Python AI and FastMCP contracts")
        self.assertEqual(JOBS["frontend"]["timeout-minutes"], "45")

    def test_package_waits_for_each_applicable_canonical_check(self):
        needs = JOBS["package"]["needs"]
        self.assertEqual(set(needs), {"changes", "backend", "frontend", "vps-health", "ai-runtime"})
        package = "\n".join(step.get("run", "") for step in JOBS["package"]["steps"])
        self.assertIn("ci_release_contract.py gate", package)
        self.assertIn("git rev-parse HEAD", package)
        change_detector = "\n".join(step.get("run", "") for step in JOBS["changes"]["steps"])
        self.assertIn('git diff --name-only "${{ github.event.before }}" HEAD', change_detector)

    def test_production_job_consumes_same_run_artifact(self):
        self.assertEqual(JOBS["deploy"]["needs"], ["package"])
        self.assertEqual(JOBS["deploy"]["uses"], "./.github/workflows/deploy-stoxla-production.yml")
        self.assertEqual(set(DEPLOY_WORKFLOW["on"]), {"workflow_call"})
        deploy = DEPLOY_WORKFLOW["jobs"]["deploy"]
        self.assertEqual(deploy["concurrency"]["group"], "stoxla-production-deploy")
        downloads = [step["with"]["name"] for step in deploy["steps"]
                     if step.get("uses", "").startswith("actions/download-artifact")]
        self.assertEqual(downloads, ["stoxla-release-${{ github.sha }}"])

    def test_current_master_sha_is_checked_fail_closed_before_activation(self):
        deploy = DEPLOY_WORKFLOW["jobs"]["deploy"]
        activation_step = next(step for step in deploy["steps"] if step.get("name") == "Upload and activate release")
        script = activation_step["run"]
        lookup = script.index("https://api.github.com/repos/${GITHUB_REPOSITORY}/branches/master")
        compare = script.index('if master_sha != candidate_sha:')
        activate = script.rindex("stoxla-deploy-release.sh")
        self.assertLess(lookup, compare)
        self.assertLess(compare, activate)
        self.assertIn('Authorization: Bearer ${GITHUB_TOKEN}', script)
        self.assertIn('master_sha != candidate_sha', script)
        self.assertIn("raise SystemExit(f\"Production activation blocked:", script)
        self.assertIn("curl --fail --silent --show-error", script)
        self.assertIn("set -euo pipefail", script)
        self.assertEqual(activation_step["env"]["GITHUB_TOKEN"], "${{ github.token }}")
        self.assertEqual(deploy["concurrency"]["group"], "stoxla-production-deploy")

    def test_same_run_artifact_and_exact_sha_post_deploy_contract_remain(self):
        deploy = DEPLOY_WORKFLOW["jobs"]["deploy"]
        activation = next(step for step in deploy["steps"] if step.get("name") == "Upload and activate release")
        post_deploy = next(step for step in deploy["steps"] if step.get("name") == "Post-deploy health check")
        download = next(step for step in deploy["steps"] if step.get("uses", "").startswith("actions/download-artifact"))
        upload = next(step for step in JOBS["package"]["steps"]
                      if step.get("uses", "").startswith("actions/upload-artifact"))
        self.assertEqual(upload["with"]["name"], "stoxla-release-${{ github.sha }}")
        self.assertEqual(download["with"]["name"], "stoxla-release-${{ github.sha }}")
        self.assertIn('STOXLA_EXPECTED_COMMIT=\'${RELEASE_SHA}\'', activation["run"])
        self.assertEqual(post_deploy["env"]["EXPECTED_SHA"], "${{ github.sha }}")
        self.assertIn("actual != sys.argv[2]", post_deploy["run"])

    def test_no_separate_master_deployment_workflow_or_duplicate_verifiers(self):
        self.assertNotIn("push", DEPLOY_WORKFLOW["on"])
        self.assertNotIn("workflow_dispatch", DEPLOY_WORKFLOW["on"])
        self.assertIn("github.event_name == 'push'", DEPLOY_WORKFLOW["jobs"]["deploy"]["if"])
        backend_calls = sum("verify-ci.sh --backend" in step.get("run", "")
                            for step in JOBS["backend"]["steps"])
        frontend_calls = sum("verify-ci.sh --frontend" in step.get("run", "")
                             for step in JOBS["frontend"]["steps"])
        self.assertEqual((backend_calls, frontend_calls), (1, 1))


if __name__ == "__main__":
    unittest.main()
