<?php

namespace App\Console\Commands;

use App\Services\SourceGenerationAuditService;
use Illuminate\Console\Command;

/**
 * Inspects one immutable source generation by its database primary key.
 * It intentionally does not enqueue, settle, mutate, or promote anything.
 */
class AuditSourceGeneration extends Command
{
    protected $signature = 'trading:audit-source-generation
        {source_generation_id : Immutable lab_generations primary-key ID (not the generation label)}
        {--json : Emit the complete provenance report as JSON}';

    protected $description = 'Read-only source-generation, arm, replay, and settlement provenance audit';

    public function handle(SourceGenerationAuditService $audit): int
    {
        $report = $audit->audit((int) $this->argument('source_generation_id'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($report['status'] === 'missing_generation') {
            $this->warn('Source generation topilmadi: '.$report['source_generation_id']);

            return self::SUCCESS;
        }

        $this->table(
            ['Source ID', 'Generation', 'Market', 'Status', 'Arms', 'Controls', 'All terminal', 'Next action'],
            [[
                $report['source_generation_id'],
                $report['generation_label'],
                ($report['symbol'] ?? 'unknown').'/'.($report['timeframe'] ?? 'unknown'),
                $report['generation_status'],
                $report['arm_count'],
                $report['control_arm_count'],
                $report['all_arms_terminal'] ? 'yes' : 'no',
                $report['next_action'],
            ]],
        );
        $this->line('Promotion evidence: false (read-only audit).');

        return self::SUCCESS;
    }
}
