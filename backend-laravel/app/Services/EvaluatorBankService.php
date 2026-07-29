<?php

namespace App\Services;

use App\Models\AdversarialValidatorFinding;
use App\Models\EvaluatorReputation;

/** Separates red-team diagnosis from certification and keeps false positives visible. */
class EvaluatorBankService
{
    public function refresh(): void
    {
        AdversarialValidatorFinding::query()->selectRaw('validator, count(*) as findings, sum(verdict = "passed") as survived')
            ->groupBy('validator')->get()->each(function ($row): void {
                $findings = (int) $row->findings; $survived = (int) $row->survived;
                EvaluatorReputation::updateOrCreate(['validator' => $row->validator], [
                    'findings_count' => $findings, 'forward_confirmed_count' => 0, 'false_positive_count' => 0,
                    'reputation_score' => $findings ? round(100 * (1 - ($survived / $findings)), 3) : 0,
                    'passport_status' => 'waiting_for_forward_confirmation',
                    'evidence' => ['diagnosis_layer' => 'replay findings recorded', 'certification_layer' => 'not assessed without later forward outcome',
                        'rule' => 'A validator is not trusted merely because it reports many failures.'],
                ]);
            });
    }
}
