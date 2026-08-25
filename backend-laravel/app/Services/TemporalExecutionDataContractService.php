<?php

namespace App\Services;

/** Certifies the data plane before a temporal role can claim executable M1 precision. */
class TemporalExecutionDataContractService
{
    public const PROTOCOL = 'xauusd_temporal_execution_data_contract_v1';

    /** @return array<string,mixed> */
    public function certify(array $input): array
    {
        $required = ['m1_canonical','bid_ask_history','spread_history','slippage_model','latency_model','deterministic_aggregation','gap_audit','closed_at_available_at','backward_only_alignment'];
        $missing = array_values(array_filter($required, fn (string $key): bool => ! (bool) ($input[$key] ?? false)));
        $m1 = $missing === [];
        return ['protocol'=>self::PROTOCOL,'base_provider'=>$input['provider'] ?? null,'aggregation'=>['M5'=>'deterministic_from_M1','M15'=>'deterministic_from_M1','M30'=>'deterministic_from_M1','H1'=>'deterministic_from_M1'],'m1_execution'=>$m1,'m5_canonical'=>(bool) ($input['m5_canonical'] ?? $m1),'closed_candle_only'=>(bool) ($input['closed_at_available_at'] ?? false),'backward_only_alignment'=>(bool) ($input['backward_only_alignment'] ?? false),'m1_false_precision_blocked'=>!$m1,'missing_requirements'=>$missing,'promotion_evidence'=>false];
    }
}
