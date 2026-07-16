from dataclasses import dataclass

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe


@dataclass(frozen=True)
class WalkForwardService:
    train_years: int = 8
    validation_years: int = 2
    forward_years: int = 2
    step_years: int = 2
    final_holdout_years: int = 2
    overfit_threshold: int = 25
    minimum_windows: int = 3

    def split_dataset(self, df: pd.DataFrame) -> dict[str, pd.DataFrame]:
        """Compatibility helper; production scoring uses rolling_windows()."""
        normalized = self._normalize(df)
        train_end = int(len(normalized) * 0.70)
        validation_end = train_end + int(len(normalized) * 0.15)
        if train_end == 0 or validation_end <= train_end or validation_end >= len(normalized):
            raise ValueError("Dataset is too small for walk-forward split.")
        return {
            "train": normalized.iloc[:train_end].reset_index(drop=True),
            "validation": normalized.iloc[train_end:validation_end].reset_index(drop=True),
            "forward": normalized.iloc[validation_end:].reset_index(drop=True),
        }

    def rolling_windows(self, df: pd.DataFrame) -> tuple[list[dict[str, pd.DataFrame]], pd.DataFrame]:
        normalized = self._normalize(df)
        first = normalized["time"].min()
        holdout_start = normalized["time"].max() - pd.DateOffset(years=self.final_holdout_years)
        windows: list[dict[str, pd.DataFrame]] = []
        cursor = first

        while True:
            train_end = cursor + pd.DateOffset(years=self.train_years)
            validation_end = train_end + pd.DateOffset(years=self.validation_years)
            forward_end = validation_end + pd.DateOffset(years=self.forward_years)
            if forward_end > holdout_start:
                break

            window = {
                "train": normalized[(normalized.time >= cursor) & (normalized.time < train_end)],
                "validation": normalized[(normalized.time >= train_end) & (normalized.time < validation_end)],
                "forward": normalized[(normalized.time >= validation_end) & (normalized.time < forward_end)],
            }
            if all(len(segment) >= 2 for segment in window.values()):
                windows.append({key: value.reset_index(drop=True) for key, value in window.items()})
            cursor += pd.DateOffset(years=self.step_years)

        holdout = normalized[normalized.time >= holdout_start].reset_index(drop=True)
        if len(windows) >= self.minimum_windows:
            return windows, holdout

        # Historical vendor archives can contain multi-month gaps. A strict
        # calendar split would reject otherwise valid data solely because one
        # calendar interval has no candles. Preserve chronology and the final
        # untouched two-year holdout, then build expanding rolling windows by
        # observed rows before that holdout.
        row_windows = self._row_rolling_windows(normalized, holdout_start)
        if len(row_windows) < self.minimum_windows:
            raise ValueError(
                "Rolling walk-forward uchun kamida 3 ta oyna va 2 yillik final holdout kerak."
            )

        return row_windows, holdout

    def _row_rolling_windows(
        self,
        normalized: pd.DataFrame,
        holdout_start: pd.Timestamp,
    ) -> list[dict[str, pd.DataFrame]]:
        selection = normalized[normalized.time < holdout_start].reset_index(drop=True)
        if len(selection) < 30:
            return []

        segment_size = max(2, int(len(selection) * 0.12))
        windows: list[dict[str, pd.DataFrame]] = []

        for train_ratio in (0.45, 0.55, 0.65):
            train_end = int(len(selection) * train_ratio)
            validation_end = train_end + segment_size
            forward_end = validation_end + segment_size
            if forward_end > len(selection):
                continue

            window = {
                "train": selection.iloc[:train_end].reset_index(drop=True),
                "validation": selection.iloc[train_end:validation_end].reset_index(drop=True),
                "forward": selection.iloc[validation_end:forward_end].reset_index(drop=True),
            }
            if all(len(segment) >= 2 for segment in window.values()):
                windows.append(window)

        return windows

    def run(self, payload: SimpleBacktestRequest, df: pd.DataFrame, score_calculator) -> dict[str, object]:
        windows, holdout = self.rolling_windows(df)
        evaluations: list[dict[str, object]] = []

        for index, segments in enumerate(windows, start=1):
            results = {
                name: self._run_segment(payload, segment, name)
                for name, segment in segments.items()
            }
            scores = {name: score_calculator(result) for name, result in results.items()}
            evaluations.append({
                "window": index,
                "periods": {
                    name: f"{segment.time.min().date()} - {segment.time.max().date()}"
                    for name, segment in segments.items()
                },
                "scores": scores,
                "results": results,
                "is_overfit": detect_overfit(scores["train"], scores["forward"], self.overfit_threshold),
            })

        train_score = round(sum(item["scores"]["train"] for item in evaluations) / len(evaluations))
        validation_score = round(sum(item["scores"]["validation"] for item in evaluations) / len(evaluations))
        forward_scores = [item["scores"]["forward"] for item in evaluations]
        forward_score = round(sum(forward_scores) / len(forward_scores))
        robustness_score = calculate_robustness_score(train_score, validation_score, *forward_scores)
        overfit_windows = sum(bool(item["is_overfit"]) for item in evaluations)
        is_overfit = overfit_windows > len(evaluations) / 2
        representative = dict(evaluations[-1]["results"]["forward"])
        forward_results = [item["results"]["forward"] for item in evaluations]
        representative["total_trades"] = sum(int(result.get("total_trades", 0)) for result in forward_results)
        representative["displayed_trade_count"] = len(representative.get("trades", []))
        representative["trade_ledger_scope"] = "latest forward window, last 20 trades; headline metrics aggregate all rolling forward windows"
        representative["profit_factor"] = min(float(result.get("profit_factor", 0)) for result in forward_results)
        representative["max_drawdown"] = max(float(result.get("max_drawdown", 0)) for result in forward_results)
        representative["max_drawdown_percent"] = max(
            float(result.get("max_drawdown_percent", result.get("max_drawdown", 0)))
            for result in forward_results
        )
        representative["monte_carlo"] = {
            **(representative.get("monte_carlo", {}) or {}),
            "risk_of_ruin_percent": max(
                float((result.get("monte_carlo", {}) or {}).get("risk_of_ruin_percent", 100))
                for result in forward_results
            ),
        }

        return {
            "train_score": train_score,
            "validation_score": validation_score,
            "forward_score": forward_score,
            "forward_window_scores": forward_scores,
            "rolling_windows_count": len(evaluations),
            "robustness_score": robustness_score,
            "is_overfit": is_overfit,
            "result": {
                **representative,
                "train_score": train_score,
                "validation_score": validation_score,
                "forward_score": forward_score,
                "forward_window_scores": forward_scores,
                "rolling_windows_count": len(evaluations),
                "robustness_score": robustness_score,
                "is_overfit": is_overfit,
                "walk_forward": {
                    "mode": "rolling",
                    "windows": evaluations,
                    "final_holdout": {
                        "period": f"{holdout.time.min().date()} - {holdout.time.max().date()}",
                        "rows": len(holdout),
                        "used_for_selection": False,
                    },
                },
            },
        }

    def _run_segment(self, payload: SimpleBacktestRequest, segment: pd.DataFrame, name: str) -> dict[str, object]:
        data = run_simple_ema_rsi_backtest_on_dataframe(payload, segment).model_dump()
        data["walk_forward_segment"] = name
        return data

    @staticmethod
    def _normalize(df: pd.DataFrame) -> pd.DataFrame:
        if df.empty:
            raise ValueError("Dataset is empty.")
        normalized = df.copy()
        normalized["time"] = pd.to_datetime(normalized["time"])
        return normalized.sort_values("time").reset_index(drop=True)


def calculate_robustness_score(*scores: int | float) -> int:
    if not scores:
        return 0
    return round(max(min(100 - (max(scores) - min(scores)), 100), 0))


def detect_overfit(train_score: int | float, forward_score: int | float, threshold: int = 25) -> bool:
    return train_score - forward_score > threshold
