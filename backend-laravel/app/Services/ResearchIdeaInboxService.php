<?php

namespace App\Services;

use App\Models\ResearchIdeaInboxEntry;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Converts external or agent ideas into bounded research hypotheses only. */
class ResearchIdeaInboxService
{
    public const PROTOCOL = 'research_idea_inbox_v1';

    /** @return array<string,mixed> */
    public function submit(array $idea): array
    {
        $symbol = strtoupper((string) data_get($idea, 'symbol', 'XAUUSD'));
        $timeframe = strtoupper((string) data_get($idea, 'timeframe', 'H1'));
        $title = trim((string) data_get($idea, 'title', ''));
        $hypothesis = trim((string) data_get($idea, 'hypothesis', ''));
        $genes = $this->normalizeGenes((array) data_get($idea, 'bounded_genes', []));
        if ($title === '' || $hypothesis === '' || $genes === []) {
            throw new InvalidArgumentException('An idea needs a title, falsifiable hypothesis and at least one bounded gene.');
        }
        if ($symbol !== 'XAUUSD') {
            throw new InvalidArgumentException('The current cooperative inbox is scoped to XAUUSD.');
        }

        $sourceType = strtolower((string) data_get($idea, 'source_type', 'agent'));
        $sourceReference = trim((string) data_get($idea, 'source_reference', '')) ?: null;
        $canonical = [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'title' => $title,
            'hypothesis' => $hypothesis, 'bounded_genes' => $genes,
        ];
        $ideaKey = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $compatibility = [
            'protocol' => self::PROTOCOL,
            'duplicate_key' => $ideaKey,
            'same_symbol_only' => true,
            'existing_safety_gates_immutable' => true,
            'direct_install_forbidden' => true,
            'direct_inheritance_forbidden' => true,
            'candidate_control_required' => true,
            'status' => 'compatible_research_hypothesis',
        ];
        $executable = [
            'protocol' => self::PROTOCOL,
            'context_scope' => (array) data_get($idea, 'context_scope', []),
            'component_species' => (array) data_get($idea, 'component_species', []),
            'required_block_type' => (string) data_get($idea, 'required_block_type', 'novelty_pair'),
            'stopping_rule' => (string) data_get($idea, 'stopping_rule', 'screen_then_frozen_control_then_independent_replay'),
            'authority_ceiling' => 'information_credit_until_causal_settlement',
            'promotion_evidence' => false,
        ];
        if (! Schema::hasTable('research_idea_inbox_entries')) {
            return [...$canonical, 'protocol' => self::PROTOCOL, 'idea_key' => $ideaKey,
                'status' => 'migration_pending', 'compatibility_contract' => $compatibility,
                'executable_contract' => $executable, 'promotion_evidence' => false];
        }
        $entry = ResearchIdeaInboxEntry::query()->firstOrCreate(['idea_key' => $ideaKey], [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'source_type' => $sourceType,
            'source_reference' => $sourceReference, 'title' => $title, 'hypothesis' => $hypothesis,
            'compatibility_contract' => $compatibility, 'executable_contract' => $executable,
            'bounded_genes' => $genes, 'status' => 'ready_for_experiment',
        ]);

        return ['protocol' => self::PROTOCOL, 'idea_key' => $entry->idea_key, 'entry_id' => $entry->id,
            'status' => $entry->wasRecentlyCreated ? 'ready_for_experiment' : 'duplicate_reused',
            'direct_runtime_authority' => false, 'promotion_evidence' => false];
    }

    /** @return array<int,array<string,mixed>> */
    public function ready(string $symbol, string $timeframe, int $limit = 20): array
    {
        if (! Schema::hasTable('research_idea_inbox_entries')) return [];

        return ResearchIdeaInboxEntry::query()->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->where('status', 'ready_for_experiment')
            ->oldest('id')->limit(max(1, min(100, $limit)))->get()->map(fn (ResearchIdeaInboxEntry $entry): array => [
                'id' => $entry->id, 'idea_key' => $entry->idea_key, 'hypothesis' => $entry->hypothesis,
                'bounded_genes' => $entry->bounded_genes, 'executable_contract' => $entry->executable_contract,
            ])->all();
    }

    public function assign(int $id, string $blockKey): void
    {
        if (! Schema::hasTable('research_idea_inbox_entries')) return;
        ResearchIdeaInboxEntry::query()->whereKey($id)->where('status', 'ready_for_experiment')->update([
            'status' => 'assigned_to_frozen_experiment', 'assigned_block_key' => $blockKey,
        ]);
    }

    public function settle(string $blockKey, array $receipt): void
    {
        if (! Schema::hasTable('research_idea_inbox_entries')) return;
        ResearchIdeaInboxEntry::query()->where('assigned_block_key', $blockKey)->update([
            'status' => 'settled_waiting_for_causal_promotion',
            'evidence_receipt' => [...$receipt, 'direct_inheritance_allowed' => false, 'promotion_evidence' => false],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeGenes(array $genes): array
    {
        return collect($genes)->map(function (mixed $gene, mixed $key): ?array {
            $row = is_array($gene) ? $gene : ['key' => is_string($key) ? $key : (string) $gene];
            $name = trim((string) data_get($row, 'key', ''));
            if ($name === '') return null;
            return ['key' => $name, 'minimum' => data_get($row, 'minimum'), 'maximum' => data_get($row, 'maximum'),
                'allowed_values' => array_values((array) data_get($row, 'allowed_values', []))];
        })->filter()->values()->all();
    }
}
