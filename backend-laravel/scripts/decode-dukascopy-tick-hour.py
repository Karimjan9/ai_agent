"""Decode a hash-bound Dukascopy XAUUSD BI5 tick hour without any network I/O."""

import argparse
import datetime as dt
import hashlib
import json
import lzma
import math
import pathlib
import struct

MAX_RAW_BYTES = 33_554_432
MAX_DECODED_BYTES = 20_000_000


def decode(path: pathlib.Path, expected_sha256: str, hour_text: str) -> dict:
    hour = dt.datetime.strptime(hour_text, "%Y-%m-%dT%H:00:00.000Z").replace(tzinfo=dt.timezone.utc)
    if hour.strftime("%Y-%m-%dT%H:00:00.000Z") != hour_text or hour.year >= 2026:
        raise ValueError("RECOVERY_BI5_HOUR_INVALID")
    if path.stat().st_size > MAX_RAW_BYTES:
        raise ValueError("RECOVERY_BI5_RAW_BOUND_EXCEEDED")
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != expected_sha256:
        raise ValueError("RECOVERY_BI5_SHA_MISMATCH")
    decoder = lzma.LZMADecompressor(format=lzma.FORMAT_AUTO, memlimit=134_217_728)
    data = decoder.decompress(raw, max_length=MAX_DECODED_BYTES + 1)
    if len(data) > MAX_DECODED_BYTES or not decoder.eof or decoder.unused_data:
        raise ValueError("RECOVERY_BI5_DECOMPRESSION_BOUND_OR_TRAILING_BYTES")
    if not data or len(data) % 20:
        raise ValueError("RECOVERY_BI5_RECORD_LENGTH_INVALID")
    result = {"timestamp": int(hour.timestamp()) * 1000, "multiplier": 0.001,
              "ask": None, "bid": None, "times": [], "asks": [], "bids": [],
              "askVolumes": [], "bidVolumes": []}
    previous_offset = 0
    previous_ask = previous_bid = None
    for offset, ask, bid, ask_volume, bid_volume in struct.iter_unpack(">IIIff", data):
        if offset < previous_offset or offset >= 3_600_000:
            raise ValueError("RECOVERY_BI5_TIMESTAMP_INVALID")
        if bid <= 0 or ask < bid or not all(math.isfinite(value) and value >= 0 for value in (ask_volume, bid_volume)):
            raise ValueError("RECOVERY_BI5_PRICE_OR_VOLUME_INVALID")
        if previous_ask is None:
            result["ask"], result["bid"] = ask / 1000, bid / 1000
            previous_ask, previous_bid = ask, bid
        result["times"].append(offset - previous_offset)
        result["asks"].append(ask - previous_ask)
        result["bids"].append(bid - previous_bid)
        result["askVolumes"].append(ask_volume * 1_000_000)
        result["bidVolumes"].append(bid_volume * 1_000_000)
        previous_offset, previous_ask, previous_bid = offset, ask, bid
    if hashlib.sha256(path.read_bytes()).hexdigest() != expected_sha256:
        raise ValueError("RECOVERY_BI5_SOURCE_CHANGED")
    return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--raw", type=pathlib.Path, required=True)
    parser.add_argument("--sha256", required=True)
    parser.add_argument("--hour", required=True)
    arguments = parser.parse_args()
    print(json.dumps(decode(arguments.raw, arguments.sha256, arguments.hour), allow_nan=False, separators=(",", ":")))
