import unittest

from app.services.red_team import RedTeamService


class RedTeamServiceTest(unittest.TestCase):
    def test_cost_stress_failure_produces_bounded_risk_exit_recommendation(self):
        result = {
            "pf_attribution": {
                "stress_cost": {"profit_factor": 0.82},
                "by_session": {"london": {"trades": 4, "net_pf": 0.9}},
                "by_exit_reason": {"intrabar_stop": {"trades": 2}},
            },
            "volatility_performance": {"high_volatility": {"profit_factor": 0.75}},
        }

        report = RedTeamService().evaluate(result)

        self.assertEqual(report["scenarios"]["double_cost_execution"]["status"], "assessed")
        self.assertFalse(report["scenarios"]["double_cost_execution"]["pass"])
        self.assertIn("high_volatility_risk_multiplier", report["recommendations"])
        self.assertEqual(report["scenarios"]["news_window"]["status"], "not_assessed")


if __name__ == "__main__":
    unittest.main()
