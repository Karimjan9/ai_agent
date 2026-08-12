"""Selection-bias diagnostics for strategy batches.

The routines in this module deliberately consume replay/checkpoint evidence
only. The sealed holdout is not part of CSCV/PBO selection or parameter
ranking. Score-only CSCV is explicitly diagnostic; purged/embargoed CSCV needs
label intervals.
"""

from __future__ import annotations

from datetime import date, datetime, timezone
from itertools import combinations
import math
import random
from statistics import mean, pstdev
from typing import Iterable


_EULER_MASCHERONI = 0.5772156649015329


def returns_from_equity_curve(equity_curve: Iterable[float]) -> list[float]:
    """Return simple per-trade returns from an equity curve, in decimal form."""
    values = [float(value) for value in equity_curve if value is not None]
    return [current / previous - 1 for previous, current in zip(values, values[1:]) if previous > 0]


def per_trade_sharpe(returns: list[float]) -> float | None:
    if len(returns) < 2:
        return None
    deviation = pstdev(returns)
    if deviation == 0:
        return None
    return mean(returns) / deviation


def deflated_sharpe_ratio(returns: list[float], trial_sharpes: list[float]) -> dict[str, object]:
    """Return the probability that a Sharpe survives multiple testing.

    This follows the Deflated Sharpe Ratio framework with a per-trade Sharpe
    because the backtester has irregular trade timestamps.  It is intentionally
    not annualised: the benchmark and observed Sharpe therefore share the same
    unit and no trading-frequency assumption is introduced.
    """
    observed = per_trade_sharpe(returns)
    usable_trials = [float(value) for value in trial_sharpes if math.isfinite(float(value))]
    if observed is None or len(returns) < 10:
        return {
            "status": "insufficient_trade_returns",
            "method": "deflated_sharpe_ratio_per_trade",
            "trade_return_count": len(returns),
            "number_of_trials": len(usable_trials),
        }
    if len(usable_trials) < 2:
        return {
            "status": "not_applicable_single_trial",
            "method": "deflated_sharpe_ratio_per_trade",
            "observed_sharpe": round(observed, 6),
            "trade_return_count": len(returns),
            "number_of_trials": len(usable_trials),
        }

    expected_max = _expected_max_sharpe(usable_trials)
    skewness = _skewness(returns)
    excess_kurtosis = _excess_kurtosis(returns)
    denominator_squared = 1 - skewness * observed + (excess_kurtosis / 4) * observed * observed
    if denominator_squared <= 0:
        return {
            "status": "invalid_moments",
            "method": "deflated_sharpe_ratio_per_trade",
            "observed_sharpe": round(observed, 6),
            "benchmark_sharpe": round(expected_max, 6),
            "number_of_trials": len(usable_trials),
        }

    z_score = (observed - expected_max) * math.sqrt(len(returns) - 1) / math.sqrt(denominator_squared)
    probability = _normal_cdf(z_score)
    return {
        "status": "assessed",
        "method": "deflated_sharpe_ratio_per_trade",
        "observed_sharpe": round(observed, 6),
        "benchmark_sharpe": round(expected_max, 6),
        "deflated_sharpe_probability": round(probability, 6),
        "z_score": round(z_score, 6),
        "trade_return_count": len(returns),
        "number_of_trials": len(usable_trials),
        "skewness": round(skewness, 6),
        "excess_kurtosis": round(excess_kurtosis, 6),
    }


def noise_label_permutation_test(
    values: list[float], simulations: int = 200, seed: int = 42
) -> dict[str, object]:
    """Check whether the observed trade edge beats a sign-randomized null.

    This is a bounded noise sanity test, not a leakage proof.  It preserves
    the observed return magnitudes and randomizes only win/loss labels, so a
    pipeline that manufactures a positive result from selection noise is
    unlikely to survive the null distribution.
    """
    usable = [float(value) for value in values if value is not None and math.isfinite(float(value)) and float(value) != 0]
    wins = sum(value for value in usable if value > 0)
    losses = abs(sum(value for value in usable if value < 0))
    observed_pf = wins / losses if losses else (99.0 if wins else 0.0)
    if len(usable) < 30:
        return {
            "status": "insufficient_data",
            "pass": False,
            "method": "sign_permutation_noise_null",
            "trade_count": len(usable),
            "required_trade_count": 30,
            "promotion_evidence": False,
        }

    rng = random.Random(seed)
    magnitudes = [abs(value) for value in usable]
    null_pfs: list[float] = []
    for _ in range(max(50, int(simulations))):
        randomized = [magnitude if rng.random() >= 0.5 else -magnitude for magnitude in magnitudes]
        null_wins = sum(value for value in randomized if value > 0)
        null_losses = abs(sum(value for value in randomized if value < 0))
        null_pfs.append(null_wins / null_losses if null_losses else (99.0 if null_wins else 0.0))
    exceedances = sum(value >= observed_pf for value in null_pfs)
    p_value = (exceedances + 1) / (len(null_pfs) + 1)
    return {
        "status": "assessed",
        "pass": p_value <= 0.10,
        "method": "sign_permutation_noise_null",
        "trade_count": len(usable),
        "simulations": len(null_pfs),
        "observed_profit_factor": round(observed_pf, 6),
        "null_95_percentile_profit_factor": round(sorted(null_pfs)[max(0, int(len(null_pfs) * .95) - 1)], 6),
        "p_value": round(p_value, 6),
        "seed": seed,
        "promotion_evidence": True,
        "rule": "Randomized labels preserve return magnitudes; p<=0.10 is required for the robustness protocol.",
    }


def cscv_probability_of_backtest_overfitting(score_rows: list[list[float]]) -> dict[str, object]:
    """Estimate PBO with combinatorially symmetric cross-validation.

    Rows are candidates, columns are chronological *replay* checkpoints.  Each
    split selects on one half of the checkpoints and ranks the selected strategy
    on the complementary half.  A selected candidate below the OOS median is a
    backtest-overfit observation.  CSCV is only reported for an even number of
    at least four aligned checkpoints.
    """
    aligned = [row for row in score_rows if row and all(math.isfinite(float(score)) for score in row)]
    if len(aligned) < 2:
        return _pbo_insufficient("at least two candidates with aligned checkpoint scores are required", len(aligned), 0)
    checkpoint_count = min(len(row) for row in aligned)
    if checkpoint_count < 4 or checkpoint_count % 2:
        return _pbo_insufficient("an even number of at least four checkpoint windows is required", len(aligned), checkpoint_count)
    aligned = [row[:checkpoint_count] for row in aligned]
    half = checkpoint_count // 2
    split_results: list[dict[str, object]] = []
    below_median = 0
    for in_sample in combinations(range(checkpoint_count), half):
        out_of_sample = tuple(index for index in range(checkpoint_count) if index not in in_sample)
        in_scores = [mean(row[index] for index in in_sample) for row in aligned]
        selected_index = max(range(len(aligned)), key=lambda index: (in_scores[index], -index))
        out_scores = [mean(row[index] for index in out_of_sample) for row in aligned]
        ordered = sorted(range(len(aligned)), key=lambda index: (-out_scores[index], index))
        oos_rank = ordered.index(selected_index) + 1
        is_below_median = oos_rank > len(aligned) / 2
        below_median += int(is_below_median)
        split_results.append({
            "in_sample_windows": [index + 1 for index in in_sample],
            "out_of_sample_windows": [index + 1 for index in out_of_sample],
            "selected_candidate_index": selected_index,
            "selected_oos_rank": oos_rank,
            "selected_oos_percentile": round((len(aligned) - oos_rank + 1) / len(aligned), 6),
            "below_oos_median": is_below_median,
        })
    probability = below_median / len(split_results)
    return {
        "status": "assessed",
        "method": "combinatorially_symmetric_cross_validation",
        "purge_embargo_applied": False,
        "diagnostic_only": True,
        "promotion_evidence": False,
        "warning": "Score-only CSCV is a PBO diagnostic; it is not purged financial CPCV.",
        "candidate_count": len(aligned),
        "checkpoint_count": checkpoint_count,
        "split_count": len(split_results),
        "probability_of_backtest_overfitting": round(probability, 6),
        "overfit_split_count": below_median,
        "splits": split_results,
    }


def purged_cscv_probability_of_backtest_overfitting(
    score_rows: list[list[float]],
    window_intervals: list[dict[str, object]] | None = None,
    *,
    purge_bars: int = 0,
    embargo_bars: int = 0,
) -> dict[str, object]:
    """Run CSCV only when checkpoint labels have explicit time intervals.

    A score-only split cannot know whether a trade label from the training
    side reaches into the test side.  This protocol therefore refuses to
    call itself financial CPCV unless every checkpoint has ``start``,
    ``end``, ``label_start`` and ``label_end`` metadata.  Purging removes
    training windows whose labels overlap the test labels; embargo removes a
    configurable number of neighbouring windows around the test fold.

    ``purge_bars`` and ``embargo_bars`` are intentionally expressed as
    neighbouring observed windows here.  A future candle-level implementation
    can pass one interval per bar without changing the contract.
    """
    if not window_intervals:
        diagnostic = cscv_probability_of_backtest_overfitting(score_rows)
        diagnostic.update({
            "method": "purged_embargoed_combinatorially_symmetric_cross_validation",
            "protocol": "purged_embargoed_cscv_v1",
            "purge_embargo_applied": False,
            "diagnostic_only": True,
            "promotion_evidence": False,
            "reason": "Checkpoint label intervals are missing; score-only PBO remains diagnostic.",
        })
        return diagnostic

    aligned = [row for row in score_rows if row and all(math.isfinite(float(score)) for score in row)]
    checkpoint_count = min((len(row) for row in aligned), default=0)
    if len(aligned) < 2:
        return {
            "status": "insufficient_data",
            "method": "purged_embargoed_combinatorially_symmetric_cross_validation",
            "protocol": "purged_embargoed_cscv_v1",
            "candidate_count": len(aligned),
            "checkpoint_count": checkpoint_count,
            "purge_embargo_applied": False,
            "diagnostic_only": False,
            "promotion_evidence": False,
            "reason": "At least two competing candidates are required for CSCV/PBO.",
        }
    interval_rows = list(window_intervals[:checkpoint_count])
    if checkpoint_count < 4 or checkpoint_count % 2 or len(interval_rows) != checkpoint_count:
        return {
            "status": "insufficient_data",
            "method": "purged_embargoed_combinatorially_symmetric_cross_validation",
            "protocol": "purged_embargoed_cscv_v1",
            "candidate_count": len(aligned),
            "checkpoint_count": checkpoint_count,
            "purge_embargo_applied": False,
            "diagnostic_only": False,
            "promotion_evidence": False,
            "reason": "An even set of at least four aligned checkpoint intervals is required.",
        }

    intervals: list[dict[str, float]] = []
    for index, row in enumerate(interval_rows):
        if not isinstance(row, dict):
            return _purged_cscv_metadata_error(len(aligned), checkpoint_count, "Interval metadata is not an object.")
        start = _interval_number(row.get("start"))
        end = _interval_number(row.get("end"))
        label_start = _interval_number(row.get("label_start", row.get("start")))
        label_end = _interval_number(row.get("label_end", row.get("end")))
        if None in (start, end, label_start, label_end) or end < start or label_end < label_start:
            return _purged_cscv_metadata_error(
                len(aligned), checkpoint_count,
                f"Checkpoint {index + 1} lacks valid start/end and label_start/label_end bounds.",
            )
        intervals.append({
            "start": float(start), "end": float(end),
            "label_start": float(label_start), "label_end": float(label_end),
        })

    purge_bars = max(0, int(purge_bars))
    embargo_bars = max(0, int(embargo_bars))
    half = checkpoint_count // 2
    split_results: list[dict[str, object]] = []
    below_median = 0
    purged_indices: set[int] = set()
    embargoed_indices: set[int] = set()
    label_overlap_count = sum(
        _intervals_overlap(intervals[left]["label_start"], intervals[left]["label_end"],
                           intervals[right]["label_start"], intervals[right]["label_end"])
        for left, right in combinations(range(checkpoint_count), 2)
    )

    for in_sample in combinations(range(checkpoint_count), half):
        out_of_sample = tuple(index for index in range(checkpoint_count) if index not in in_sample)
        blocked_by_purge: list[int] = []
        blocked_by_embargo: list[int] = []
        for index in in_sample:
            overlaps_test_label = any(
                _intervals_overlap(intervals[index]["label_start"], intervals[index]["label_end"],
                                   intervals[test]["label_start"], intervals[test]["label_end"])
                for test in out_of_sample
            )
            if overlaps_test_label:
                blocked_by_purge.append(index)
            if any(abs(index - test) <= purge_bars + embargo_bars for test in out_of_sample):
                if index not in blocked_by_purge:
                    blocked_by_embargo.append(index)

        eligible = [index for index in in_sample if index not in blocked_by_purge and index not in blocked_by_embargo]
        purged_indices.update(blocked_by_purge)
        embargoed_indices.update(blocked_by_embargo)
        if not eligible:
            continue

        in_scores = [mean(row[index] for index in eligible) for row in aligned]
        selected_index = max(range(len(aligned)), key=lambda index: (in_scores[index], -index))
        out_scores = [mean(row[index] for index in out_of_sample) for row in aligned]
        ordered = sorted(range(len(aligned)), key=lambda index: (-out_scores[index], index))
        oos_rank = ordered.index(selected_index) + 1
        is_below_median = oos_rank > len(aligned) / 2
        below_median += int(is_below_median)
        split_results.append({
            "in_sample_windows": [index + 1 for index in in_sample],
            "eligible_in_sample_windows": [index + 1 for index in eligible],
            "purged_windows": [index + 1 for index in blocked_by_purge],
            "embargoed_windows": [index + 1 for index in blocked_by_embargo],
            "out_of_sample_windows": [index + 1 for index in out_of_sample],
            "selected_candidate_index": selected_index,
            "selected_oos_rank": oos_rank,
            "selected_oos_percentile": round((len(aligned) - oos_rank + 1) / len(aligned), 6),
            "below_oos_median": is_below_median,
        })

    if not split_results:
        return _purged_cscv_metadata_error(
            len(aligned), checkpoint_count,
            "Purging and embargo removed every in-sample fold; no valid split remains.",
        ) | {
            "purged_window_count": len(purged_indices),
            "embargoed_window_count": len(embargoed_indices),
        }

    probability = below_median / len(split_results)
    return {
        "status": "assessed",
        "method": "purged_embargoed_combinatorially_symmetric_cross_validation",
        "protocol": "purged_embargoed_cscv_v1",
        "candidate_count": len(aligned),
        "checkpoint_count": checkpoint_count,
        "split_count": len(split_results),
        "probability_of_backtest_overfitting": round(probability, 6),
        "overfit_split_count": below_median,
        "purge_bars": purge_bars,
        "embargo_bars": embargo_bars,
        "purged_window_count": len(purged_indices),
        "embargoed_window_count": len(embargoed_indices),
        "label_overlap_detected": label_overlap_count > 0,
        "label_overlap_pair_count": label_overlap_count,
        "purge_embargo_applied": True,
        "diagnostic_only": False,
        "promotion_evidence": True,
        "splits": split_results,
    }


def _purged_cscv_metadata_error(candidate_count: int, checkpoint_count: int, reason: str) -> dict[str, object]:
    return {
        "status": "insufficient_data",
        "method": "purged_embargoed_combinatorially_symmetric_cross_validation",
        "protocol": "purged_embargoed_cscv_v1",
        "candidate_count": candidate_count,
        "checkpoint_count": checkpoint_count,
        "purge_embargo_applied": False,
        "diagnostic_only": False,
        "promotion_evidence": False,
        "reason": reason,
    }


def _interval_number(value: object) -> float | None:
    if isinstance(value, datetime):
        normalized = value.replace(tzinfo=timezone.utc) if value.tzinfo is None else value.astimezone(timezone.utc)
        return normalized.timestamp()
    if isinstance(value, date):
        return datetime(value.year, value.month, value.day, tzinfo=timezone.utc).timestamp()
    if isinstance(value, str):
        try:
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
            parsed = parsed.replace(tzinfo=timezone.utc) if parsed.tzinfo is None else parsed.astimezone(timezone.utc)
            return parsed.timestamp()
        except ValueError:
            try:
                return float(value)
            except ValueError:
                return None
    try:
        number = float(value)
        return number if math.isfinite(number) else None
    except (TypeError, ValueError):
        return None


def _intervals_overlap(left_start: float, left_end: float, right_start: float, right_end: float) -> bool:
    return max(left_start, right_start) <= min(left_end, right_end)


def bootstrap_profit_factor_lower_bound(values: list[float], simulations: int = 500, seed: int = 42) -> dict[str, object]:
    """Deterministic 5% PF lower bound from closed-trade resampling."""
    if len(values) < 20:
        return {"status": "insufficient_trades", "trade_count": len(values), "method": "bootstrap_profit_factor"}
    rng = random.Random(seed)
    pfs: list[float] = []
    for _ in range(simulations):
        sample = [values[rng.randrange(len(values))] for _ in values]
        wins = sum(value for value in sample if value > 0)
        losses = abs(sum(value for value in sample if value <= 0))
        pfs.append(wins / losses if losses else 99.0)
    pfs.sort()
    return {
        "status": "assessed", "method": "bootstrap_profit_factor", "trade_count": len(values),
        "simulations": simulations, "pf_5_percentile_lower_bound": round(pfs[max(0, int(len(pfs) * .05) - 1)], 3),
    }


def _pbo_insufficient(reason: str, candidate_count: int, checkpoint_count: int) -> dict[str, object]:
    return {
        "status": "insufficient_data",
        "method": "combinatorially_symmetric_cross_validation",
        "reason": reason,
        "candidate_count": candidate_count,
        "checkpoint_count": checkpoint_count,
    }


def _expected_max_sharpe(trial_sharpes: list[float]) -> float:
    trial_count = len(trial_sharpes)
    spread = pstdev(trial_sharpes)
    if spread == 0:
        return mean(trial_sharpes)
    first_quantile = _normal_ppf(1 - 1 / trial_count)
    second_quantile = _normal_ppf(1 - 1 / (trial_count * math.e))
    return mean(trial_sharpes) + spread * ((1 - _EULER_MASCHERONI) * first_quantile + _EULER_MASCHERONI * second_quantile)


def _skewness(values: list[float]) -> float:
    deviation = pstdev(values)
    if deviation == 0:
        return 0.0
    centre = mean(values)
    return mean(((value - centre) / deviation) ** 3 for value in values)


def _excess_kurtosis(values: list[float]) -> float:
    deviation = pstdev(values)
    if deviation == 0:
        return 0.0
    centre = mean(values)
    return mean(((value - centre) / deviation) ** 4 for value in values) - 3


def _normal_cdf(value: float) -> float:
    return 0.5 * (1 + math.erf(value / math.sqrt(2)))


def _normal_ppf(probability: float) -> float:
    """Acklam's rational approximation of the inverse normal CDF."""
    probability = min(max(probability, 1e-12), 1 - 1e-12)
    a = (-39.6968302866538, 220.946098424521, -275.928510446969, 138.357751867269, -30.6647980661472, 2.50662827745924)
    b = (-54.4760987982241, 161.585836858041, -155.698979859887, 66.8013118877197, -13.2806815528857)
    c = (-0.00778489400243029, -0.322396458041136, -2.40075827716184, -2.54973253934373, 4.37466414146497, 2.93816398269878)
    d = (0.00778469570904146, 0.32246712907004, 2.445134137143, 3.75440866190742)
    if probability < 0.02425:
        q = math.sqrt(-2 * math.log(probability))
        return (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1)
    if probability > 1 - 0.02425:
        q = math.sqrt(-2 * math.log(1 - probability))
        return -(((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1)
    q = probability - 0.5
    r = q * q
    return (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q / (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1)
