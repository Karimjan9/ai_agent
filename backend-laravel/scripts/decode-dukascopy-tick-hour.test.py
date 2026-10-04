"""Contract checks for the offline native BI5 decoder."""

import hashlib
import importlib.util
import lzma
import pathlib
import struct
import tempfile
import unittest

MODULE_PATH = pathlib.Path(__file__).with_name("decode-dukascopy-tick-hour.py")
SPEC = importlib.util.spec_from_file_location("native_bi5_decoder", MODULE_PATH)
DECODER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DECODER)


class NativeBi5DecoderTest(unittest.TestCase):
    def decode(self, contents, expected_sha=None):
        with tempfile.TemporaryDirectory(prefix="native-bi5-test-") as directory:
            path = pathlib.Path(directory) / "native.bi5"
            path.write_bytes(contents)
            return DECODER.decode(path, expected_sha or hashlib.sha256(contents).hexdigest(), "2025-03-03T10:00:00.000Z")

    def test_big_endian_records_keep_synchronized_quotes_millisecond_offsets_and_volume_units(self):
        records = struct.pack(">IIIff", 50, 2868000, 2867500, 0.00018, 0.00012)
        records += struct.pack(">IIIff", 155, 2867950, 2867480, 0.00012, 0.00018)
        decoded = self.decode(lzma.compress(records, format=lzma.FORMAT_ALONE))
        self.assertEqual(decoded["times"], [50, 105])
        self.assertEqual(decoded["asks"], [0, -50])
        self.assertEqual(decoded["bids"], [0, -20])
        self.assertEqual(decoded["bid"], 2867.5)
        self.assertAlmostEqual(decoded["bidVolumes"][0], 120, places=4)

    def test_tampered_hash_trailing_bytes_partial_records_and_invalid_observations_fail_closed(self):
        valid = struct.pack(">IIIff", 50, 2868000, 2867500, 0.00018, 0.00012)
        compressed = lzma.compress(valid, format=lzma.FORMAT_ALONE)
        with self.assertRaisesRegex(ValueError, "SHA_MISMATCH"):
            self.decode(compressed, "a" * 64)
        for records in [b"", valid[:-1],
                        struct.pack(">IIIff", 3_600_000, 2868000, 2867500, 0.00018, 0.00012),
                        valid + struct.pack(">IIIff", 49, 2868000, 2867500, 0.00018, 0.00012),
                        struct.pack(">IIIff", 50, 2867000, 2867500, 0.00018, 0.00012),
                        struct.pack(">IIIff", 50, 2868000, 2867500, float("nan"), 0.00012)]:
            with self.subTest(record_length=len(records)), self.assertRaises(ValueError):
                self.decode(lzma.compress(records, format=lzma.FORMAT_ALONE))
        with self.assertRaisesRegex(ValueError, "TRAILING_BYTES"):
            self.decode(compressed + b"unattested second stream")
        with self.assertRaisesRegex(ValueError, "DECOMPRESSION_BOUND"):
            self.decode(lzma.compress(b"\0" * (DECODER.MAX_DECODED_BYTES + 1), format=lzma.FORMAT_ALONE))


if __name__ == "__main__":
    unittest.main()
