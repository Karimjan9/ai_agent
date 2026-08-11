from app.main import (
    _screening_insufficient_robustness_profile,
    _screening_robustness_admission,
)


def test_screening_robustness_is_deferred_for_a_core_failure(monkeypatch):
    monkeypatch.setenv("AI_SCREENING_ROBUSTNESS_MIN_TRADES", "10")

    admission = _screening_robustness_admission({"total_trades": 4, "profit_factor": 0.0})
    profile = _screening_insufficient_robustness_profile(
        {"total_trades": 4, "profit_factor": 0.0}, admission
    )

    assert admission["passed"] is False
    assert profile["status"] == "insufficient_evidence"
    assert "FAILED_TRADE_COUNT" in profile["reason_codes"]
    assert "FAILED_PROFIT_FACTOR" in profile["reason_codes"]
    assert len(profile["skipped_sub_replays"]) == 4
    assert profile["promotion_evidence"] is False


def test_screening_robustness_admits_candidates_that_can_reach_full_replay(monkeypatch):
    monkeypatch.setenv("AI_SCREENING_ROBUSTNESS_MIN_TRADES", "10")

    admission = _screening_robustness_admission({"total_trades": 10, "profit_factor": 1.01})

    assert admission == {
        "passed": True,
        "minimum_trades": 10,
        "total_trades": 10,
        "profit_factor": 1.01,
        "reason_codes": [],
    }
