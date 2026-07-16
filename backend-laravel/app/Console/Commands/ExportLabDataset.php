<?php
namespace App\Console\Commands;
use App\Services\LabDatasetExportService;
use Illuminate\Console\Command;
class ExportLabDataset extends Command
{
    protected $signature='market-data:export-lab {symbol} {--timeframe=H1}';
    public function handle(LabDatasetExportService $service):int{$path=$service->export(strtoupper($this->argument('symbol')),$this->option('timeframe'));$this->info($path);return self::SUCCESS;}
}
