<?php

namespace Tests\Feature;

use App\Services\FrozenM5CalendarAuditService;
use Mockery;
use Tests\TestCase;

class FrozenM5CalendarAuditCacheTest extends TestCase
{
    public function test_repeated_exact_audit_is_reused_but_changed_bytes_or_scope_are_audited_again(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'calendar-');
        try {
            file_put_contents($source, 'original-csv-bytes');
            $owner = Mockery::mock(FrozenM5CalendarAuditService::class)->makePartial()->shouldAllowMockingProtectedMethods();
            $owner->shouldReceive('execute')->times(3)->andReturnUsing(fn () => [
                'source_csv_sha256' => hash_file('sha256', $source), 'calendar_fact' => 'original']);
            $first = $owner->inspect($source, ['--inventory']);
            $this->assertSame($first, $owner->inspect($source, ['--inventory']));
            file_put_contents($source, 'changed-csv-bytes');
            $this->assertNotSame($first['source_csv_sha256'], $owner->inspect($source, ['--inventory'])['source_csv_sha256']);
            $this->assertSame('original', $owner->inspect($source, ['--inventory', '--clean-discovery=15000'])['calendar_fact']);
        } finally { unlink($source); }
    }

    public function test_a_mismatched_original_output_is_never_cached_as_verified(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'calendar-');
        try {
            file_put_contents($source, 'original');
            $owner = Mockery::mock(FrozenM5CalendarAuditService::class)->makePartial()->shouldAllowMockingProtectedMethods();
            $owner->shouldReceive('execute')->twice()->andReturn(['source_csv_sha256' => str_repeat('0', 64)]);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try { $owner->inspect($source, ['--inventory']); $this->fail('Changed original output accepted.'); }
                catch (\RuntimeException $error) { $this->assertSame('FROZEN_CALENDAR_ORIGINAL_SOURCE_DRIFT', $error->getMessage()); }
            }
        } finally { unlink($source); }
    }
}
