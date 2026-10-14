"""Synthetic canonical-column and availability guards; no provider evidence."""

import json
from pathlib import Path
import unittest

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.data_loader import load_candles
from app.services.historical_quotes import validate_historical_quotes
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures


FIXTURE = Path(__file__).parent / 'fixtures/observed_quote_canonical_synthetic.csv'


class InstrumentMethodQuoteAliasTest(unittest.TestCase):
    def data(self):
        return validate_historical_quotes(load_candles(str(FIXTURE), None), SimpleBacktestRequest(timeframe='M5'))

    def observation(self, data, index=20):
        owner = ClosedInstrumentMethodFeatures(data)
        row = data.iloc[index].to_dict()
        return owner.inputs(row, direction='BUY',
            observed_at=(row['time'] + pd.Timedelta(minutes=5)).isoformat())

    def test_synthetic_canonical_header_is_preserved_and_complete_quote_shape_is_available(self):
        provenance = json.loads(FIXTURE.with_suffix('.provenance.json').read_text())
        self.assertTrue(provenance['synthetic'])
        self.assertTrue(provenance['software_fixture_only'])
        self.assertFalse(provenance['provider_data'])
        self.assertFalse(provenance['economic_qualification'])
        data = self.data()
        self.assertEqual(len(data), provenance['selected_rows'])
        self.assertEqual(data.iloc[0]['time'], pd.Timestamp(provenance['selected_start_utc']))
        self.assertEqual(data.iloc[-1]['time'], pd.Timestamp(provenance['selected_last_open_utc']))
        self.assertIn('spread', data.columns)
        self.assertNotIn('bid_ask_spread', data.columns)
        self.assertTrue(data.spread_available.all())
        self.assertEqual(data.attrs['quote_spread_quality']['available_rows'], 24)
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertEqual(sum(row['method_quote_available'] for row in owner.features), 11)
        observed = self.observation(data)
        self.assertTrue(observed['method_quote_available'])
        self.assertAlmostEqual(observed['method_spread_atr'],
            data.iloc[20]['spread'] / owner.features[20]['_atr'])
        self.assertFalse(self.observation(data, index=12)['method_quote_available'])

    def test_equal_finite_aliases_agree_but_conflicts_and_malformed_aliases_refuse(self):
        data = self.data()
        data['bid_ask_spread'] = data.spread
        self.assertTrue(self.observation(data)['method_quote_available'])
        actual = data.iloc[20]['spread']
        for alias, value in [('bid_ask_spread', actual + .0000001),
                *[(column, malformed) for column in ('spread', 'bid_ask_spread')
                    for malformed in (float('nan'), float('inf'), True, 'invalid', None)]]:
            with self.subTest(alias=alias, value=value):
                changed = data.copy()
                changed[alias] = changed[alias].astype(object)
                changed.at[20, alias] = value
                observed = self.observation(changed)
                self.assertFalse(observed['method_quote_available'])
                self.assertNotIn('method_spread_atr', observed)

    def test_legacy_only_observed_alias_is_supported_with_identical_provenance_guards(self):
        data = self.data().rename(columns={'spread': 'bid_ask_spread'})
        self.assertTrue(self.observation(data)['method_quote_available'])
        for column, value in [('spread_available', False), ('bid_close', data.iloc[20]['close'] + 1),
                ('ask_close', data.iloc[20]['ask_close'] + 1),
                ('quote_time_utc', data.iloc[20]['time'] + pd.Timedelta(minutes=6)),
                ('quote_available_after_utc', data.iloc[20]['time'] + pd.Timedelta(minutes=4))]:
            with self.subTest(column=column):
                changed = data.copy()
                changed[column] = changed[column].astype(object)
                changed.at[20, column] = value
                self.assertFalse(self.observation(changed)['method_quote_available'])

    def test_modeled_or_unflagged_spread_still_cannot_supply_observed_quotes(self):
        data = self.data()
        missing = data.drop(columns=['bid_close', 'ask_close', 'quote_time_utc', 'quote_available_after_utc'])
        missing['spread_points'] = 0.
        self.assertFalse(self.observation(missing)['method_quote_available'])
        self.assertFalse(self.observation(data.drop(columns=['spread_available']))['method_quote_available'])


if __name__ == '__main__':
    unittest.main()
