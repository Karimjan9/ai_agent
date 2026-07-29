<?php

namespace App\Services;

use App\Models\ModelVersion;
use App\Models\TransferMatrixEntry;

class TransferMatrixService
{
    public function sync(ModelVersion $model, array $result): array
    {
        $matrix = [['XAUUSD', 'EURUSD'], ['XAUUSD', 'GBPUSD'], ['EURUSD', 'XAUUSD'], ['EURUSD', 'GBPUSD'],
            ['GBPUSD', 'XAUUSD'], ['GBPUSD', 'EURUSD'], ['XAUUSD+EURUSD', 'GBPUSD'], ['EURUSD+GBPUSD', 'XAUUSD'], ['XAUUSD+GBPUSD', 'EURUSD']];
        $reported = collect((array) data_get($result, 'transfer_matrix.entries', []))->keyBy(fn ($entry) => data_get($entry, 'train_markets').'|'.data_get($entry, 'test_market'));
        $entries = [];
        foreach ($matrix as [$train, $test]) {
            $evidence = (array) $reported->get($train.'|'.$test, []);
            $complete = isset($evidence['from_scratch_score'], $evidence['transferred_score'], $evidence['adaptation_steps'], $evidence['source_regression']);
            $entry = TransferMatrixEntry::updateOrCreate(['model_version_id' => $model->id, 'train_markets' => $train, 'test_market' => $test, 'test_scope' => 'frozen_unseen'], [
                'from_scratch_score' => $evidence['from_scratch_score'] ?? null, 'transferred_score' => $evidence['transferred_score'] ?? null,
                'transfer_gain' => $complete ? (float) $evidence['transferred_score'] - (float) $evidence['from_scratch_score'] : null,
                'adaptation_steps' => $evidence['adaptation_steps'] ?? null, 'source_regression' => $evidence['source_regression'] ?? null,
                'status' => $complete ? 'reported_requires_audit' : 'waiting_for_frozen_replay', 'evidence' => $evidence ?: ['rule' => 'No transfer claim until from-scratch and transferred frozen replays both exist.'],
            ]);
            $entries[] = ['id' => $entry->id, 'train_markets' => $train, 'test_market' => $test, 'status' => $entry->status];
        }
        return ['protocol' => 'transfer_matrix_v1', 'entries' => $entries, 'rule' => 'Champion and paper status never transfer between markets.'];
    }
}
