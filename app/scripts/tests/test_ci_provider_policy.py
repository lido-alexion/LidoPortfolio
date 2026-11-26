from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class CiProviderPolicyTests(unittest.TestCase):
    def test_php_mandatory_test_base_blocks_stray_http_requests(self):
        test_case = (ROOT / "app/tests/TestCase.php").read_text()
        self.assertIn("Http::preventStrayRequests();", test_case)

    def test_fixture_parser_and_client_tests_remain_in_phpunit_suites(self):
        phpunit = (ROOT / "app/phpunit.xml").read_text()
        parser_test = ROOT / "app/tests/Unit/Fundamentals/NseXbrlFactsParserTest.php"
        client_test = ROOT / "app/tests/Feature/V8/NseIntegratedFilingClientTest.php"
        self.assertTrue(parser_test.is_file())
        self.assertTrue(client_test.is_file())
        self.assertIn("<directory>tests/Unit</directory>", phpunit)
        self.assertIn("<directory>tests/Feature</directory>", phpunit)
        self.assertIn("Http::fake", client_test.read_text())

    def test_live_provider_monitor_is_separate_and_not_a_release_dependency(self):
        monitor = (ROOT / ".github/workflows/provider-smoke.yml").read_text()
        ci = (ROOT / ".github/workflows/ci.yml").read_text()
        policy = (ROOT / "docs/current/ci-provider-dependency-policy.md").read_text()
        self.assertIn("schedule:", monitor)
        self.assertIn("workflow_dispatch:", monitor)
        self.assertIn("provider_smoke.py", monitor)
        self.assertNotIn("provider-smoke", ci)
        self.assertIn("Fixture and mocked tests", policy)
        self.assertIn("Nonblocking live checks", policy)
        self.assertIn("HTTP 404/429", policy)
        self.assertIn("does not create GitHub issues automatically", policy)

    def test_smoke_report_never_emits_response_body_or_credentials(self):
        script = (ROOT / "scripts/provider_smoke.py").read_text()
        self.assertIn("response.read(1)", script)
        self.assertIn("type(error).__name__", script)
        self.assertNotIn("response.read()", script)
        self.assertNotIn("Authorization", script)
        self.assertNotIn("Set-Cookie", script)


if __name__ == "__main__":
    unittest.main()
