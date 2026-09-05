<?php

namespace App\Services;

use App\Models\LabGeneration;

/**
 * Declares which immutable evidence boundary closes a laboratory generation.
 *
 * Ordinary populations require a screening decision for every seat. Direct
 * causal-replay cohorts enter at full replay by design, so treating their
 * absent screening rows as live work leaves a false EVIDENCE_IN_PROGRESS
 * monitor forever. This service changes reporting only; it grants no gate,
 * parent, paper, or trading authority.
 */
class LabGenerationEvidenceContractService
{
    public const PROTOCOL = 'lab_generation_evidence_contract_v1';

    private const DIRECT_REPLAY_TRIGGERS = [
        'edge_genesis',
        'edge_component_attribution',
        'skill_cartridge_transplant',
        'skill_cartridge_interaction',
        'authority_incubator',
    ];

    /** @return array<string, mixed> */
    public function for(LabGeneration $generation): array
    {
        $directReplay = in_array((string) $generation->trigger_type, self::DIRECT_REPLAY_TRIGGERS, true);

        return [
            'protocol' => self::PROTOCOL,
            'generation_id' => (int) $generation->id,
            'trigger_type' => (string) $generation->trigger_type,
            'mode' => $directReplay ? 'direct_replay' : 'screening_pipeline',
            'screening_evidence_required' => ! $directReplay,
            'terminal_evaluation_required' => $directReplay,
            'promotion_evidence' => false,
            'rule' => $directReplay
                ? 'A direct causal-replay generation is final only after every seat has a terminal immutable evaluation run; a screening row is not expected.'
                : 'An ordinary generation is final only after every seat has its terminal screening evidence.',
        ];
    }
}
