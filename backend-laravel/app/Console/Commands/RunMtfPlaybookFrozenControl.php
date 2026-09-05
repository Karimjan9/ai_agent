<?php

namespace App\Console\Commands;

use App\Services\AgentResearchPlaybookToolboxService;
use App\Services\MtfPlaybookFrozenControlService;
use App\Services\StrategyResearchCatalogueService;
use Illuminate\Console\Command;

class RunMtfPlaybookFrozenControl extends Command
{
    protected $signature = 'trading:mtf-playbook-frozen-control
        {symbol=XAUUSD : Primary market symbol}
        {--model= : One of the 17 StrategyResearchCatalogue model ids}
        {--next : Run only the next unreplayed toolbox prior}
        {--related-symbol= : Required only for smt_sweep_mss}
        {--json : Print complete immutable replay records as JSON}';

    protected $description = 'Run each H4/H1/M15/M5 catalogue playbook against the same immutable M5 frozen control';

    public function handle(
        MtfPlaybookFrozenControlService $runner,
        StrategyResearchCatalogueService $catalogue,
        AgentResearchPlaybookToolboxService $toolbox,
    ): int {
        $symbol = (string) $this->argument('symbol');
        $model = trim((string) $this->option('model'));
        $related = trim((string) $this->option('related-symbol')) ?: null;

        try {
            if ($model !== '') {
                $rows = [$runner->run($symbol, $catalogue->model($model)['id'], $related)];
            } elseif ((bool) $this->option('next')) {
                $next = $toolbox->nextFrozenPriorTrial([], $symbol);
                $nextId = (string) data_get($next, 'tool.id', '');
                $rows = $nextId !== ''
                    ? [$runner->run($symbol, $nextId, $related)]
                    : [[
                        'research_model_id' => '',
                        'status' => 'catalogue_frozen_priors_complete',
                        'reason_codes' => [],
                        'promotion_evidence' => false,
                    ]];
            } else {
                $rows = $runner->runAll($symbol, $related);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('MTF playbook frozen-control runner xatosi: '.$exception->getMessage());
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Model', 'Status', 'Data hash', 'PF delta', 'Net % delta', 'DD % delta', 'Reason'],
                array_map(static function (array $row): array {
                    $delta = (array) data_get($row, 'comparison.delta', []);
                    return [
                        (string) ($row['research_model_id'] ?? ''),
                        (string) ($row['status'] ?? ''),
                        substr((string) ($row['data_hash'] ?? ''), 0, 12),
                        $delta['profit_factor'] ?? 'n/a',
                        $delta['net_profit_percent'] ?? 'n/a',
                        $delta['max_drawdown_percent'] ?? 'n/a',
                        implode(', ', (array) ($row['reason_codes'] ?? [])),
                    ];
                }, $rows),
            );
            $this->comment('Har qatorda control va candidate bir xil immutable manifest, M5 next-open hamda execution hash bilan ishlaydi. promotion_evidence=false.');
        }

        return collect($rows)->contains(fn (array $row): bool => in_array(
            $row['status'] ?? '',
            ['completed', 'blocked', 'catalogue_frozen_priors_complete'],
            true,
        ))
            ? self::SUCCESS
            : self::FAILURE;
    }
}
