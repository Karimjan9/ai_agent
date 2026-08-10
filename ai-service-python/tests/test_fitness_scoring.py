from app.main import build_fitness_breakdown, calculate_strategy_score


def quality_result(months, stress_pf=1.20, cost_drag=5.0):
    return {
        "net_profit_percent": 12.0,
        "winrate": 60.0,
        "total_trades": 40,
        "profit_factor": 1.5,
        "max_drawdown_percent": 5.0,
        "max_consecutive_losses": 2,
        "stability_score": 70,
        "regime_performance": {
            "trend_up": {"trades": 15, "profit_factor": 1.4},
            "range": {"trades": 15, "profit_factor": 1.2},
        },
        "monthly_passport": {"months": months},
        "pf_attribution": {
            "stress_cost": {"profit_factor": stress_pf},
            "summary": {"cost_to_gross_profit_percent": cost_drag},
        },
    }


def test_fitness_prefers_monthly_survival_over_same_global_profit():
    good = quality_result([
        {"trades": 12, "profit_factor": 1.3, "net_profit_percent": 2.0},
        {"trades": 12, "profit_factor": 1.1, "net_profit_percent": 1.0},
        {"trades": 12, "profit_factor": 1.2, "net_profit_percent": 1.5},
    ])
    bad = quality_result([
        {"trades": 12, "profit_factor": 1.3, "net_profit_percent": 2.0},
        {"trades": 12, "profit_factor": 0.5, "net_profit_percent": -2.0},
        {"trades": 12, "profit_factor": 1.2, "net_profit_percent": 1.5},
    ])

    assert calculate_strategy_score(good) > calculate_strategy_score(bad)
    assert build_fitness_breakdown(bad)["components"]["worst_month_pf"] == 0.5


def test_fitness_penalizes_execution_cost_stress():
    normal = quality_result([
        {"trades": 12, "profit_factor": 1.2, "net_profit_percent": 1.0},
        {"trades": 12, "profit_factor": 1.1, "net_profit_percent": 1.0},
    ])
    expensive = quality_result(
        normal["monthly_passport"]["months"], stress_pf=0.8, cost_drag=35.0,
    )

    assert calculate_strategy_score(normal) > calculate_strategy_score(expensive)

