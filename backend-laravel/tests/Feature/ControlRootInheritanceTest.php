<?php

namespace Tests\Feature;

use App\Models\LabAgentInheritanceAudit;
use App\Services\LabAgentPreflightService;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRootInheritanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parentless_first_generation_is_explicitly_marked_without_a_parent(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'first_generation_root', true, 'H1', [], true, false, 4);
        $this->assertNotNull($generation);

        foreach ($generation->agents()->with(['modelVersion', 'inheritanceAudits'])->get() as $agent) {
            $this->assertSame(
                'no_parent_available',
                data_get($agent->modelVersion->metadata, 'parent_inheritance_protocol.parent_selection'),
            );
            $this->assertSame(
                'no_parent_available',
                data_get($agent->modelVersion->metadata, 'semantic_lineage.mode'),
            );
            $inspection = app(LabAgentPreflightService::class)->inspect($agent, 'screening');
            $this->assertTrue($inspection['passed'], json_encode($inspection, JSON_UNESCAPED_SLASHES));
            $this->assertSame('no_parent_available', $inspection['parent_mode']);
            $this->assertSame('not_available', $inspection['parent_status']);
        }
    }

    public function test_legacy_parentless_metadata_is_reported_as_no_parent_not_quarantined(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'legacy_parentless_metadata', true, 'H1', [], true, false, 4);
        $this->assertNotNull($generation);

        $agent = $generation->agents()->with('modelVersion')->firstOrFail();
        $metadata = (array) $agent->modelVersion->metadata;
        data_set($metadata, 'parent_inheritance_protocol.parent_selection', 'archive_candidates_not_parent_eligible');
        $agent->modelVersion->update(['metadata' => $metadata]);

        $inspection = app(LabAgentPreflightService::class)->inspect($agent->fresh(['modelVersion']), 'screening');

        $this->assertTrue($inspection['passed'], json_encode($inspection, JSON_UNESCAPED_SLASHES));
        $this->assertSame('no_parent_available', $inspection['parent_mode']);
        $this->assertSame('not_available', $inspection['parent_status']);
        $this->assertNotContains('ROOT_SEED_PROTOCOL_MISSING', $inspection['errors']);
    }

    public function test_control_root_seed_handoff_is_explicit_and_auditable(): void
    {
        $service = app(LabPopulationService::class);
        $rootGeneration = $service->build(
            'XAUUSD',
            'control_root_seed_test',
            true,
            'H1',
            [],
            true,
            false,
            4,
        );
        $this->assertNotNull($rootGeneration);
        $rootGeneration->update(['status' => 'completed']);

        $childGeneration = $service->build(
            'XAUUSD',
            'control_root_specialist_test',
            true,
            'H1',
            [],
            true,
            false,
            4,
        );
        $this->assertNotNull($childGeneration);
        $children = $childGeneration->agents()
            ->with(['modelVersion', 'parentLinks', 'inheritanceAudits'])
            ->whereNotNull('parent_a_model_version_id')
            ->get();
        $this->assertCount(4, $children);

        foreach ($children as $child) {
            $contract = (array) data_get($child->modelVersion->metadata, 'control_root_specialist_inheritance');
            $this->assertSame('control_root_specialist_inheritance_v1', $contract['protocol']);
            $this->assertSame('accepted', $contract['status']);
            $this->assertSame($child->parent_a_model_version_id, $contract['root_model_version_id']);
            $this->assertSame(1, $contract['changed_parameter_count']);
            $this->assertSame(
                [$child->parent_a_model_version_id],
                $child->parentLinks->where('relation_type', 'control_root_seed')->pluck('parent_model_version_id')->all(),
            );
            $this->assertTrue($child->inheritanceAudits->contains(
                fn (LabAgentInheritanceAudit $audit): bool => $audit->transition === 'control_root_to_specialist'
                    && $audit->decision === 'accepted'
                    && $audit->contract_hash === $contract['contract_hash'],
            ));

            $inspection = app(LabAgentPreflightService::class)->inspect($child, 'screening');
            $this->assertTrue($inspection['passed'], json_encode($inspection, JSON_UNESCAPED_SLASHES));
            $this->assertSame('control_root_seed_inheritance', $inspection['parent_mode']);
        }
    }

    public function test_tampered_control_root_handoff_is_rejected_before_replay(): void
    {
        $service = app(LabPopulationService::class);
        $rootGeneration = $service->build('XAUUSD', 'control_root_tamper_seed', true, 'H1', [], true, false, 4);
        $this->assertNotNull($rootGeneration);
        $rootGeneration->update(['status' => 'completed']);

        $childGeneration = $service->build('XAUUSD', 'control_root_tamper_child', true, 'H1', [], true, false, 4);
        $this->assertNotNull($childGeneration);
        $child = $childGeneration->agents()->with('modelVersion')->whereNotNull('parent_a_model_version_id')->firstOrFail();
        $metadata = (array) $child->modelVersion->metadata;
        $metadata['control_root_specialist_inheritance']['status'] = 'rejected';
        $child->modelVersion->update(['metadata' => $metadata]);

        $inspection = app(LabAgentPreflightService::class)->inspect($child->fresh(), 'screening');
        $this->assertFalse($inspection['passed']);
        $this->assertContains('CONTROL_ROOT_INHERITANCE_INVALID', $inspection['errors']);
    }
}
