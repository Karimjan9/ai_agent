<?php

namespace App\Console\Commands;

use App\Services\ResearchWindowProvenanceAuditService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AuditResearchWindowProvenance extends Command
{
    protected $signature = 'trading:audit-research-window-provenance
        {--candidate-start= : UTC physical-event start, inclusive}
        {--candidate-end= : UTC physical-event end, exclusive}
        {--proposal= : JSON design file for a dry-run future preregistration}
        {--json : Print the complete bounded read-only receipt}';

    protected $description = 'Audit historical exposure and draft an existing-owner future reservation without authorizing replay';

    public function handle(ResearchWindowProvenanceAuditService $service): int
    {
        try {
            $audit = $service->audit($this->option('candidate-start'), $this->option('candidate-end'));
            $result = ['audit' => $audit];
            if (is_string($path = $this->option('proposal')) && $path !== '') {
                if (! is_file($path) || filesize($path) > 65536) throw new InvalidArgumentException('PROPOSAL_FILE_MISSING_OR_TOO_LARGE');
                $proposal = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($proposal) || array_is_list($proposal)) throw new InvalidArgumentException('PROPOSAL_MUST_BE_A_JSON_OBJECT');
                $result['future_preregistration'] = $service->preregistration($proposal, $audit);
            }
            $this->line($this->option('json') ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $audit['dependency_status'].': '.$audit['reason_code'].'; historical unused windows demonstrated=0; audit='.$audit['audit_hash']);
            // A successful audit may honestly report a blocked dependency. It never admits work.
            return self::SUCCESS;
        } catch (InvalidArgumentException|\JsonException $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
