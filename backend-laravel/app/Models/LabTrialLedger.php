<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class LabTrialLedger extends Model
{
    protected $table = 'lab_trial_ledger';

    protected $fillable = [
        'lab_generation_id', 'lab_agent_id', 'model_version_id', 'symbol', 'timeframe',
        'strategy_family', 'stage', 'run_id', 'parameter_hash', 'data_manifest_hash',
        'identity_fingerprint', 'identity_status',
        'execution_hash', 'trial_index', 'trial_count', 'score', 'observed_sharpe',
        'selection_penalty_points', 'selection_adjusted_score', 'status', 'metrics', 'evaluated_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'evaluated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $ledger): void {
            // Historical unresolved rows remain readable for audit, but no
            // new canonical row may enter the selection plane without both
            // immutable hashes that define its replay identity.
            if ((string) $ledger->getAttribute('identity_status') !== 'canonical') return;

            $dataHash = (string) $ledger->getAttribute('data_manifest_hash');
            $fingerprint = (string) $ledger->getAttribute('identity_fingerprint');
            if (! preg_match('/^[a-f0-9]{64}$/i', trim($dataHash))) {
                throw new RuntimeException('TRIAL_IDENTITY_DATASET_HASH_MISSING');
            }
            if (! preg_match('/^[a-f0-9]{64}$/i', trim($fingerprint))) {
                throw new RuntimeException('TRIAL_IDENTITY_FINGERPRINT_MISSING');
            }
        });
    }
}
