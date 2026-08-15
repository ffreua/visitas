<?php

namespace Tests\Unit;

use App\Services\BackupRetentionPolicy;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackupRetentionPolicyTest extends TestCase
{
    public function test_keeps_daily_backups_within_window_and_deletes_older_daily_duplicates(): void
    {
        $now = Carbon::parse('2026-08-15 23:00:00');
        $policy = new BackupRetentionPolicy;

        $backups = [
            ['path' => 'day0-early', 'timestamp' => Carbon::parse('2026-08-15 01:00:00')],
            ['path' => 'day0-late', 'timestamp' => Carbon::parse('2026-08-15 20:00:00')],
            ['path' => 'day1', 'timestamp' => Carbon::parse('2026-08-14 10:00:00')],
        ];

        $toDelete = $policy->pathsToDelete($backups, $now, ['daily' => 7, 'weekly' => 4, 'monthly' => 12]);

        // Apenas um backup por dia deveria sobreviver — o mais recente do dia.
        $this->assertContains('day0-early', $toDelete);
        $this->assertNotContains('day0-late', $toDelete);
        $this->assertNotContains('day1', $toDelete);
    }

    public function test_deletes_backups_outside_all_retention_windows(): void
    {
        $now = Carbon::parse('2026-08-15 12:00:00');
        $policy = new BackupRetentionPolicy;

        $backups = [
            ['path' => 'ancient', 'timestamp' => Carbon::parse('2020-01-01 12:00:00')],
            ['path' => 'recent', 'timestamp' => Carbon::parse('2026-08-15 08:00:00')],
        ];

        $toDelete = $policy->pathsToDelete($backups, $now, ['daily' => 7, 'weekly' => 4, 'monthly' => 12]);

        $this->assertContains('ancient', $toDelete);
        $this->assertNotContains('recent', $toDelete);
    }

    public function test_keeps_one_monthly_snapshot_beyond_daily_and_weekly_windows(): void
    {
        $now = Carbon::parse('2026-08-15 12:00:00');
        $policy = new BackupRetentionPolicy;

        $backups = [
            ['path' => 'month-3-a', 'timestamp' => Carbon::parse('2026-05-10 12:00:00')],
            ['path' => 'month-3-b', 'timestamp' => Carbon::parse('2026-05-20 12:00:00')],
        ];

        $toDelete = $policy->pathsToDelete($backups, $now, ['daily' => 7, 'weekly' => 4, 'monthly' => 12]);

        $this->assertCount(1, $toDelete);
        $survivors = array_diff(array_column($backups, 'path'), $toDelete);
        $this->assertCount(1, $survivors);
    }
}
