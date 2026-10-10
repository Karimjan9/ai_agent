<?php

namespace Tests\Feature;

use App\Services\ScopedResearchAuthorityService;
use App\Services\LabImmutableEvidenceService;
use App\Models\ModelVersion;
use LogicException;
use Tests\TestCase;

class ScopedOriginalSafetyTest extends TestCase
{
    public function test_bare_original_outcome_context_does_not_require_or_invent_instrument_activation(): void
    {
        $result = ['trade_ledger' => [], 'trade_ledger_hash' => hash('sha256', '[]'),
            'instrument_research_trace' => ['status' => 'not_applicable'],
            'scoped_research_context_trace' => ['protocol' => 'scoped_original_trade_context_v1',
                'trade_ledger_hash' => hash('sha256', '[]'), 'trade_ledger_count' => 0,
                'context_source' => 'decision_time_trade_ledger', 'context_slice_protocol' => 'venue_phase_v1',
                'exact_context_slices' => [], 'instrument_assignment_or_activation_claimed' => false,
                'promotion_evidence' => false]];
        $method = new \ReflectionMethod(ScopedResearchAuthorityService::class, 'slice');
        $observed = $method->invoke(app(ScopedResearchAuthorityService::class), $result, [], 8);
        $this->assertSame(['trades' => 0, 'context_reached' => false], $observed);
        $result['scoped_research_context_trace']['trade_ledger_count'] = 8;
        $this->expectExceptionMessage('ORIGINAL_SCOPED_TRADE_CONTEXT_BINDING_REQUIRED');
        $method->invoke(app(ScopedResearchAuthorityService::class), $result, [], 8);
    }

    public function test_prospective_recipe_is_identity_bearing_without_changing_legacy_absent_shape(): void
    {
        $owner = app(LabImmutableEvidenceService::class);
        $model = new ModelVersion(['strategy' => 'ema_rsi', 'metadata' => []]);
        $legacy = $owner->modelRuntimeBasis($model);
        $this->assertArrayNotHasKey('prospective_scoped_composition_recipe', $legacy['components']);
        $recipe = ['protocol' => 'prospective_scoped_composition_recipe_v1', 'source_hash' => hash('sha256', 'recipe')];
        $model->metadata = ['prospective_scoped_composition_recipe' => $recipe];
        $new = $owner->modelRuntimeBasis($model);
        $this->assertSame($recipe, $new['components']['prospective_scoped_composition_recipe']);
        unset($new['components']['prospective_scoped_composition_recipe']);
        $this->assertSame($legacy, $new);
    }

    private function window(): array
    {
        return ['start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z'];
    }

    private function original(): array
    {
        return ['total_trades' => 1, 'max_drawdown_percent' => 5,
            'monte_carlo' => ['risk_of_ruin_percent' => 2],
            'statistical_evidence' => ['original_position_maturity' => ['protocol' => 'original_position_maturity_v1',
                'closed_trade_count' => 1, 'open_position_count' => 0, 'censored_trade_count' => 0,
                'unknown_maturity_count' => 0, 'forced_terminal_close_applied' => false]],
            'trade_ledger' => [['entry_time' => '2027-01-10T12:00:00Z', 'exit_time' => '2027-01-10T13:00:00Z',
                'exit_reason' => 'target']]];
    }

    public function test_external_ceiling_cannot_be_loosened_and_measured_breach_is_not_missing_data(): void
    {
        config(['services.dual_track.max_drawdown_percent' => 99, 'services.dual_track.max_risk_of_ruin_percent' => 99]);
        $result = $this->original(); $result['max_drawdown_percent'] = 16;
        $proof = app(ScopedResearchAuthorityService::class)->verifyOriginalSafety($result,
            ['design' => ['risk_guard' => ['max_drawdown_percent' => 99, 'max_risk_of_ruin_percent' => 99]]], $this->window());
        $this->assertFalse($proof['hard_risk_passed']);
        $this->assertSame(15.0, $proof['maximum_drawdown']);
        $this->assertSame(10.0, $proof['maximum_risk_of_ruin']);
        $this->assertSame(1, $proof['mature_original_trades']);
    }

    public function test_missing_censor_label_is_not_an_implicit_maturity_flag_but_real_exit_ledger_is_accepted(): void
    {
        $owner = app(ScopedResearchAuthorityService::class);
        $this->assertTrue($owner->verifyOriginalSafety($this->original(), ['design' => []], $this->window())['hard_risk_passed']);
        $result = $this->original(); unset($result['trade_ledger'][0]['exit_time']);
        $this->expectExceptionMessage('ORIGINAL_POSITION_OUTCOMES_NOT_MATURE');
        $owner->verifyOriginalSafety($result, ['design' => []], $this->window());
    }

    public function test_count_label_cannot_replace_a_missing_original_ledger(): void
    {
        $result = $this->original(); unset($result['trade_ledger']); $result['censored_trade_count'] = 0;
        $this->expectExceptionMessage('ORIGINAL_MATURE_TRADE_LEDGER_REQUIRED');
        app(ScopedResearchAuthorityService::class)->verifyOriginalSafety($result, ['design' => []], $this->window());
    }

    public function test_closed_trade_ledger_cannot_hide_a_still_open_original_position(): void
    {
        $result = $this->original(); $result['statistical_evidence']['original_position_maturity']['open_position_count'] = 1;
        $this->expectExceptionMessage('ORIGINAL_POSITION_STATE_MATURITY_REQUIRED');
        app(ScopedResearchAuthorityService::class)->verifyOriginalSafety($result, ['design' => []], $this->window());
    }

    public function test_forced_terminal_close_and_outside_window_exit_are_not_mature_independent_evidence(): void
    {
        foreach (['end_of_replay', 'censored'] as $reason) {
            $result = $this->original(); $result['trade_ledger'][0]['exit_reason'] = $reason;
            try {
                app(ScopedResearchAuthorityService::class)->verifyOriginalSafety($result, ['design' => []], $this->window());
                $this->fail('Forced terminal observation must not become a mature trade.');
            } catch (LogicException $error) {
                $this->assertSame('ORIGINAL_POSITION_OUTCOMES_NOT_MATURE', $error->getMessage());
            }
        }
        $result = $this->original(); $result['trade_ledger'][0]['exit_time'] = '2027-02-01T00:00:00Z';
        $this->expectExceptionMessage('ORIGINAL_POSITION_OUTCOMES_NOT_MATURE');
        app(ScopedResearchAuthorityService::class)->verifyOriginalSafety($result, ['design' => []], $this->window());
    }
}
