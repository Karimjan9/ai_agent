"""Selection-bias diagnostics for strategy batches.

The routines in this module deliberately consume only replay checkpoint data.
The sealed holdout is not part of CSCV/PBO selection or parameter ranking.
"""

from __future__ import annotations

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
        "candidate_count": len(aligned),
        "checkpoint_count": checkpoint_count,
        "split_count": len(split_results),
        "probability_of_backtest_overfitting": round(probability, 6),
        "overfit_split_count": below_median,
        "splits": split_results,
    }


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
