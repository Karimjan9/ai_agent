import pandas as pd

from app.services.market_sessions import apply_specialist_scope, session_membership


def test_iana_dst_changes_session_membership_and_overlap_without_fixed_utc_hours():
    times = pd.Series(
        [
            "2026-01-15T07:30:00Z",
            "2026-07-15T07:30:00Z",
            "2026-01-15T13:30:00Z",
            "2026-07-15T12:30:00Z",
        ]
    )

    sessions = session_membership(times)

    assert not bool(sessions.loc[0, "london"])
    assert bool(sessions.loc[1, "london"])
    assert sessions.loc[2, "session"] == "overlap"
    assert sessions.loc[3, "session"] == "overlap"
    assert sessions.loc[2, "new_york_instance_id"].endswith("|-0500")
    assert sessions.loc[3, "new_york_instance_id"].endswith("|-0400")


def test_specialist_abstains_outside_owned_session_and_keeps_overlap_mask():
    frame = pd.DataFrame(
        {
            "time": ["2026-01-15T07:30:00Z", "2026-07-15T07:30:00Z"],
            "signal": ["BUY", "BUY"],
            "signal_confidence": [0.8, 0.8],
            "market_regime": ["trend_up", "trend_up"],
            "volatility_regime": ["normal_volatility", "normal_volatility"],
        }
    )
    contract = {
        "protocol": "contextual_council_allocator_v1",
        "session": "london",
        "regime": "trend_up",
        "volatility": "normal_volatility",
        "session_ownership": {
            "protocol": "market_session_calendar_v1",
            "calendar_version": "test-v1",
            "session": "london",
        },
    }

    scoped = apply_specialist_scope(frame, contract)

    assert scoped.loc[0, "signal"] == "WAIT"
    assert scoped.loc[0, "specialist_scope_reason"] == "outside_owned_context_wait"
    assert scoped.loc[1, "signal"] == "BUY"
    assert scoped.loc[1, "specialist_scope_eligible"]
    assert isinstance(scoped.loc[1, "session_overlap_mask"], list)
