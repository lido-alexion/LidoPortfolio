import contextlib
import io
import importlib.util
import unittest


SPEC = importlib.util.spec_from_file_location(
    "yahoo_fundamentals",
    "scripts/yahoo_fundamentals.py",
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class Frame:
    empty = False
    columns = ["2026-06-30", "2026-03-31"]

    def __getitem__(self, column):
        return {
            "2026-06-30": {"Total Revenue": 120, "Net Income": float("nan")},
            "2026-03-31": {"Total Revenue": 100, "Net Income": 10},
        }[column]


class Ticker:
    quarterly_financials = Frame()
    quarterly_balance_sheet = Frame()
    quarterly_cashflow = Frame()


class AnnualTicker:
    financials = Frame()
    balance_sheet = Frame()
    cashflow = Frame()


class YahooFundamentalsAdapterTest(unittest.TestCase):
    def test_quarterly_output_is_plain_json_and_preserves_nulls(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(MODULE.main(["TCS.NS", "quarterly"], lambda _: Ticker()), 0)
        payload = __import__("json").loads(output.getvalue())
        self.assertEqual(payload["statements"]["income_statement"][0]["period_end"], "2026-06-30")
        self.assertIsNone(payload["statements"]["income_statement"][0]["facts"]["Net Income"])

    def test_annual_output_uses_annual_statement_attributes(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(MODULE.main(["TCS.NS", "annual"], lambda _: AnnualTicker()), 0)
        payload = __import__("json").loads(output.getvalue())
        self.assertEqual(payload["cadence"], "annual")
        self.assertEqual(len(payload["statements"]["income_statement"]), 2)

    def test_annual_and_empty_data_fail_explicitly(self):
        class EmptyTicker:
            financials = balance_sheet = cashflow = None

        errors = io.StringIO()
        with contextlib.redirect_stderr(errors):
            self.assertNotEqual(MODULE.main(["TCS.NS", "annual"], lambda _: EmptyTicker()), 0)
        self.assertIn("no fundamental statements", errors.getvalue())

    def test_invalid_arguments_fail_without_stdout_noise(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertNotEqual(MODULE.main(["TCS.NS", "monthly"], lambda _: Ticker()), 0)
        self.assertEqual(output.getvalue(), "")


if __name__ == "__main__":
    unittest.main()
