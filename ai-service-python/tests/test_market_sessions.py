import pandas as pd

from app.services.market_sessions import apply_specialist_scope, session_membership
from app.services.backtester import (
    _apply_portfolio_strategy_vectorized,
    _market_session_calendar_coverage,
)


def test_exact_context_quote_coverage_is_measured_before_liquidity_removes_unknown_rows():
    frame = pd.DataFrame({
        'time': pd.date_range('2025-06-02T08:00:00Z', periods=200, freq='5min'),
        'signal': ['BUY'] * 200, 'market_regime': ['trend_up'] * 2 + ['range'] * 198,
        'atr': [10.0] * 200, 'spread': [0.1] * 200,
        'spread_available': [0] * 2 + [1] * 198,
    })
    frame.attrs['quote_spread_quality'] = {
        'protocol': 'historical_quote_spread_quality_v1', 'provider_observed': True,
        'source': 'synchronized_bid_ask_tick', 'coverage': 0.99,
    }
    result = apply_specialist_scope(frame, {
        'protocol': 'prospective_repair_exact_context_v1', 'source_context_hash': 'scope-hash',
        'regime': 'trend_up', 'spread_liquidity_state': 'liquid',
    })
    scope = result.attrs['specialist_context_contract']
    receipt = scope['context_quote_quality']
    assert receipt['context_rows'] == 2
    assert receipt['observed_rows'] == 0
    assert receipt['coverage'] == 0
    assert receipt['before_liquidity_veto'] is True
    assert receipt['evaluated_rows'] == 200
    assert receipt['evaluated_start'] == '2025-06-02T08:00:00Z'
    assert scope['eligible_context_candle_count'] == 0
    assert scope['accepted_signal_count'] == 0
    assert scope['source_context_hash'] == receipt['source_context_hash']


def test_exact_context_quotes_cannot_be_created_from_modeled_spread_or_a_different_window():
    frame = pd.DataFrame({
        'time': pd.date_range('2025-06-02T08:00:00Z', periods=4, freq='5min'),
        'signal': ['BUY'] * 4, 'market_regime': ['trend_up'] * 4,
        'atr': [10.0] * 4, 'spread': [0.1] * 4, 'spread_available': [1] * 4,
    })
    contract = {'regime': 'trend_up', 'source_context_hash': 'scope-hash'}
    unverified = apply_specialist_scope(frame, contract).attrs['specialist_context_contract']['context_quote_quality']
    assert unverified['source_provenance_valid'] is False
    assert unverified['observed_rows'] == 0
    frame.attrs['quote_spread_quality'] = {
        'protocol': 'historical_quote_spread_quality_v1', 'provider_observed': True,
        'source': 'synchronized_bid_ask_tick',
    }
    selected = apply_specialist_scope(frame.iloc[2:].copy(), contract).attrs['specialist_context_contract']['context_quote_quality']
    assert selected['coverage'] == 1
    assert selected['evaluated_rows'] == 2
    assert selected['evaluated_start'] == '2025-06-02T08:10:00Z'
    assert selected['evaluated_end'] == '2025-06-02T08:15:00Z'


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


def test_detailed_sge_lbma_comex_phases_and_coordinates_are_preserved():
    times = pd.Series(
        [
            "2026-01-04T16:30:00Z",  # 00:30 Monday Shanghai, SGE night
            "2026-01-05T10:31:00Z",  # LBMA AM fix
            "2026-01-05T18:10:00Z",  # COMEX pre-settlement
            "2026-01-06T22:30:00Z",  # COMEX maintenance
            "2026-01-10T18:00:00Z",  # weekly close/quarantine
        ]
    )
    sessions = session_membership(times)

    assert sessions.loc[0, "asia_sge_night"]
    assert sessions.loc[1, "venue_phase"] == "london_am_fix"
    assert sessions.loc[1, "minutes_from_fix_or_settlement"]["lbma_am_fix"] == 1
    assert sessions.loc[2, "comex_pre_settlement"]
    assert sessions.loc[3, "venue_phase"] == "comex_maintenance"
    assert sessions.loc[3, "holiday_or_maintenance_state"]["maintenance"]
    assert sessions.loc[4, "classification_status"] == "quarantined_market_closed"
    assert sessions["classified_or_quarantined"].all()
    for column in (
        "session_instance_id", "venue_phases", "overlap_mask", "local_time",
        "utc_interval", "dst_offset", "minutes_from_open",
        "minutes_from_fix_or_settlement", "holiday_or_maintenance_state",
    ):
        assert column in sessions.columns


def test_sge_pre_holiday_night_is_closed_and_exact_phase_scope_waits_elsewhere():
    times = pd.Series(["2026-01-04T16:30:00Z"])
    sessions = session_membership(times, holidays={"sge": ["2026-01-05"]})
    assert not sessions.loc[0, "asia_sge_night"]

    frame = pd.DataFrame(
        {
            "time": ["2026-01-05T10:31:00Z", "2026-01-05T11:00:00Z"],
            "signal": ["BUY", "BUY"], "signal_confidence": [0.8, 0.8],
            "market_regime": ["trend_up", "trend_up"],
            "volatility_regime": ["normal_volatility", "normal_volatility"],
        }
    )
    scoped = apply_specialist_scope(
        frame,
        {
            "venue_phase": "london_am_fix", "session": "london",
            "regime": "trend_up", "volatility": "normal_volatility",
            "session_ownership": {"calendar_version": "test-v2", "venue_phase": "london_am_fix"},
        },
    )
    assert scoped.loc[0, "signal"] == "BUY"
    assert scoped.loc[1, "signal"] == "WAIT"
    assert scoped.attrs["specialist_context_contract"]["target_venue_phase"] == "london_am_fix"
    assert scoped.attrs["specialist_context_contract"]["out_of_scope_raw_signal_count"] == 1
    assert scoped.attrs["specialist_context_contract"]["out_of_scope_activation_count"] == 0


def test_portfolio_council_routes_on_exact_venue_phase_not_coarse_london_label():
    prepared = pd.DataFrame(
        {
            "time": ["2026-01-05T10:31:00Z", "2026-01-05T11:00:00Z"],
            "market_regime": ["trend_up", "trend_up"],
            "volatility_regime": ["normal_volatility", "normal_volatility"],
        }
    )
    member = pd.DataFrame({"signal": ["BUY", "BUY"], "signal_confidence": [0.8, 0.8]})
    routed = _apply_portfolio_strategy_vectorized(
        prepared,
        [(
            {
                "member_key": "lbma-fix-specialist", "target_regime": "trend_up",
                "target_volatility": "normal_volatility", "target_session": "london",
                "target_venue_phase": "london_am_fix", "parameters": {},
            },
            member,
        )],
    )

    assert routed.loc[0, "signal"] == "BUY"
    assert routed.loc[1, "signal"] == "WAIT"
    assert routed.loc[1, "portfolio_wait_reason"] == "no_specialist_for_state"


def test_calendar_coverage_hashes_every_candle_and_has_no_silent_unknown_state():
    frame = pd.DataFrame(
        {
            "time": ["2026-01-05T10:31:00Z", "2026-01-10T18:00:00Z"],
            "signal": ["BUY", "WAIT"],
        }
    )
    contract = {
        "venue_phase": "london_am_fix",
        "session_ownership": {"calendar_version": "coverage-v2"},
    }
    coverage = _market_session_calendar_coverage(frame, contract)

    assert coverage["classification_coverage"] == 1.0
    assert coverage["unknown_count"] == 0
    assert coverage["classified_count"] == 1
    assert coverage["quarantined_count"] == 1
    assert coverage["target_session_instance_ids"]
    assert len(coverage["opportunity_calendar_hash"]) == 64


def test_h1_candle_preserves_fix_phases_crossed_inside_its_closed_interval():
    sessions = session_membership(
        pd.Series(["2026-01-05T10:00:00Z"]), duration_minutes=60
    )

    assert sessions.loc[0, "london_pre_am_fix"]
    assert sessions.loc[0, "london_am_fix"]
    assert sessions.loc[0, "london_interfix"]
    assert sessions.loc[0, "venue_phase"] == "london_am_fix"
    assert sessions.loc[0, "candle_utc_interval"]["end"].startswith(
        "2026-01-05T11:00:00"
    )

    crossing = session_membership(
        pd.Series(["2026-01-05T12:30:00Z"]), duration_minutes=60
    )
    assert crossing.loc[0, "london_comex_overlap"]
    assert crossing.loc[0, "utc_interval"]["london_comex_overlap"]["start"] == "2026-01-05T13:00:00+00:00"
    assert crossing.loc[0, "minutes_from_open"]["london_comex_overlap"] == 0
    assert crossing.loc[0, "london_comex_overlap_instance_id"]


def test_specialist_runtime_enforces_liquidity_transition_and_maintenance_abstention():
    frame = pd.DataFrame(
        {
            "time": ["2026-01-05T10:31:00Z", "2026-01-05T10:31:30Z"],
            "signal": ["BUY", "BUY"], "signal_confidence": [.8, .8],
            "market_regime": ["trend_up", "transition"],
            "volatility_regime": ["normal_volatility", "normal_volatility"],
            "atr": [2.0, 2.0], "spread": [.2, .2],
        }
    )
    scoped = apply_specialist_scope(
        frame,
        {
            "venue_phase": "london_am_fix", "regime": "trend_up",
            "volatility": "normal_volatility", "direction": "BUY",
            "transition_state": "stable", "spread_liquidity_state": "liquid",
        },
    )
    assert scoped.loc[0, "signal"] == "BUY"
    assert scoped.loc[1, "signal"] == "WAIT"

    guard = apply_specialist_scope(
        frame,
        {"venue_phase": "london_am_fix", "execution_policy": "abstain_only"},
    )
    assert guard["signal"].eq("WAIT").all()


def test_specialist_preserves_raw_signal_and_missing_measured_liquidity_reason():
    frame = pd.DataFrame({
        "time": ["2025-01-06T11:00:00Z"], "signal": ["BUY"],
        "market_regime": ["trend_up"], "volatility_regime": ["normal_volatility"],
        "atr_regime": [2.0],
    })
    contract = {"venue_phase": "london_interfix", "regime": "trend_up",
                "volatility": "normal", "spread_liquidity_state": "normal",
                "source_context_hash": "sealed-source-context"}
    scoped = apply_specialist_scope(frame, contract)
    assert scoped.loc[0, "signal"] == "WAIT"
    assert scoped.loc[0, "pre_specialist_signal"] == "BUY"
    assert scoped.loc[0, "specialist_scope_first_veto"] == "liquidity_observation_missing"
    receipt = scoped.attrs["specialist_context_contract"]
    assert receipt["raw_strategy_signal_count"] == 1
    assert receipt["accepted_signal_count"] == 0
    assert receipt["target_regime"] == "trend_up"
    assert receipt["target_volatility"] == "normal"
    assert receipt["source_context_hash"] == "sealed-source-context"
    assert receipt["signal_first_veto_counts"] == {"liquidity_observation_missing": 1}
    # A measured, valid spread and atr_regime close that specific dependency;
    # the canonical volatility alias does not cause a false rejection.
    measured = apply_specialist_scope(frame.assign(spread=.2), contract)
    assert measured.loc[0, "signal"] == "BUY"
    assert measured.attrs["specialist_context_contract"]["eligible_context_candle_count"] == 1
    silent = apply_specialist_scope(frame.assign(spread=.2, signal="WAIT"), contract)
    assert silent.attrs["specialist_context_contract"]["eligible_context_candle_count"] == 1
    assert silent.attrs["specialist_context_contract"]["accepted_signal_count"] == 0
    unavailable = apply_specialist_scope(frame.assign(spread=.2, spread_available=False), contract)
    assert unavailable.loc[0, "signal"] == "WAIT"
    assert unavailable.loc[0, "specialist_scope_first_veto"] == "liquidity_observation_missing"
    for invalid in (-1.0, float("inf"), float("nan")):
        rejected = apply_specialist_scope(frame.assign(spread=invalid), contract)
        assert rejected.loc[0, "specialist_scope_first_veto"] == "liquidity_observation_missing"


def test_specialist_direction_veto_is_not_reported_as_owned_context():
    frame = pd.DataFrame({"time": ["2025-01-06T11:00:00Z"], "signal": ["BUY"]})
    scoped = apply_specialist_scope(frame, {"direction": "SELL"})
    assert scoped.loc[0, "signal"] == "WAIT"
    assert scoped.loc[0, "specialist_scope_first_veto"] == "direction_outside_scope"
