<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Services\LabDataEdgeAuditService;
use Illuminate\Console\Command;

class RecordLabDataEdgeAudit extends Command
{
    protected $signature = 'trading:lab-data-edge-audit {symbol} {--timeframe=H1} {--generation= : Generation that triggered the audit} {--finding= : Auditable finding and disposition}';

    protected $description = 'Record the required data/edge audit before allowing another targeted lab generation';

    public function handle(LabDataEdgeAuditService $audits): int
    {
        $finding = trim((string) $this->option('finding'));
        if ($finding === '') {
            $this->error('--finding is required; record what was checked and what was changed/falsified.');

            return self::INVALID;
        }

        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
        $generation = $lab->generations()->latest('generation')->first();
        if ($this->option('generation') !== null && (int) $this->option('generation') !== (int) $generation?->generation) {
            $this->error('Audit generation latest laboratory generation bilan mos emas; eski evidence ustiga unlock qilinmadi.');

            return self::FAILURE;
        }
        if (! $generation) {
            $this->error('Laboratory generation topilmadi.');

            return self::FAILURE;
        }
        $result = $audits->record($generation, $finding, ['source' => 'operator_command'], 'operator_command');
        if (($result['status'] ?? null) === 'deferred') {
            $this->warn("G{$generation->generation} hali {$generation->status}; audit unlock generation tugagandan keyin bajariladi.");

            return self::SUCCESS;
        }
        if (! in_array((string) ($result['status'] ?? ''), ['recorded', 'already_recorded'], true)) {
            $this->error('Data/edge audit blocked: '.(string) ($result['reason_code'] ?? 'UNKNOWN_REASON'));

            return self::FAILURE;
        }

        $this->info("{$symbol} G{$generation->generation}: data/edge audit recorded; next generation creation unlocked for an explicit data_edge_audit trigger.");

        return self::SUCCESS;
    }
}
