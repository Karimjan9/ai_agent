from datetime import date
from pathlib import Path

import pandas as pd

from app.schemas import Candle


REQUIRED_COLUMNS = {"time", "open", "high", "low", "close"}


def load_candles(
    dataset_path: str | None,
    candles: list[Candle] | None,
    from_date: date | None = None,
    to_date: date | None = None,
) -> pd.DataFrame:
    if candles:
        df = pd.DataFrame([candle.model_dump() for candle in candles])
    elif dataset_path:
        path = Path(dataset_path)
        if not path.exists():
            raise FileNotFoundError(f"Dataset not found: {dataset_path}")
        df = pd.read_csv(path)
    else:
        raise ValueError("No candle data provided.")

    missing_columns = REQUIRED_COLUMNS - set(df.columns)
    if missing_columns:
        missing = ", ".join(sorted(missing_columns))
        raise ValueError(f"Dataset is missing required columns: {missing}")

    df = df.copy()
    df["time"] = pd.to_datetime(df["time"], utc=True)
    for column in ["open", "high", "low", "close"]:
        df[column] = pd.to_numeric(df[column], errors="coerce")

    df = df.dropna(subset=["time", "open", "high", "low", "close"])
    df = df.sort_values("time").reset_index(drop=True)

    if from_date:
        df = df[df["time"].dt.date >= from_date]
    if to_date:
        df = df[df["time"].dt.date <= to_date]

    df = df.reset_index(drop=True)

    if len(df) < 20:
        raise ValueError("At least 20 candles are required for a meaningful MVP backtest.")

    return df
