<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "failed_jobs columns: " . json_encode(Schema::getColumnListing('failed_jobs')) . "\n";
echo "jobs columns: " . json_encode(Schema::getColumnListing('jobs')) . "\n";
echo "lab_agents columns: " . json_encode(Schema::getColumnListing('lab_agents')) . "\n";
echo "lab_evaluation_runs columns: " . json_encode(Schema::getColumnListing('lab_evaluation_runs')) . "\n";
echo "lab_generations columns: " . json_encode(Schema::getColumnListing('lab_generations')) . "\n";
echo "lab_gate_decision_events columns: " . json_encode(Schema::getColumnListing('lab_gate_decision_events')) . "\n";
echo "lab_evidence_artifacts columns: " . json_encode(Schema::getColumnListing('lab_evidence_artifacts')) . "\n";
echo "system_logs columns: " . json_encode(Schema::getColumnListing('system_logs')) . "\n";
echo "service_health_checks columns: " . json_encode(Schema::getColumnListing('service_health_checks')) . "\n";
