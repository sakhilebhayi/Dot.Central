<?php

namespace Tests\Unit\Services\Mines;

use App\Services\Mines\FreshnessEvaluator;
use App\Support\EquipmentState;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * docs/dot-central/03-architecture.md §5 — stale or missing telemetry maps
 * to an explicit state; nothing is ever inferred as stopped or idle.
 */
class FreshnessEvaluatorTest extends TestCase
{
    /** @return array<string, array{0:?string,1:?int,2:EquipmentState,3:string}> */
    public static function rows(): array
    {
        return [
            'active & fresh' => ['active', 60, EquipmentState::Operating, 'fresh'],
            'idle & fresh' => ['idle', 60, EquipmentState::Idle, 'fresh'],
            'offline (Dot.Mines timed out)' => ['offline', 60, EquipmentState::Offline, 'fresh'],
            'maintenance' => ['maintenance', 60, EquipmentState::Unavailable, 'fresh'],
            'active at exactly 2c is still fresh' => ['active', 1800, EquipmentState::Operating, 'fresh'],
            'active beyond 2c is stale' => ['active', 1801, EquipmentState::Stale, 'stale'],
            'idle at exactly 4c is stale' => ['idle', 3600, EquipmentState::Stale, 'stale'],
            'beyond 4c is unknown' => ['idle', 3601, EquipmentState::Unknown, 'missing'],
            'never heard is unknown' => ['active', null, EquipmentState::Unknown, 'missing'],
            'unexpected status is unknown' => ['launching', 60, EquipmentState::Unknown, 'fresh'],
        ];
    }

    #[DataProvider('rows')]
    public function test_state_table(?string $status, ?int $ageSeconds, EquipmentState $expected, string $freshness): void
    {
        $now = CarbonImmutable::parse('2026-10-15 12:00:00');
        $lastHeard = $ageSeconds === null ? null : $now->subSeconds($ageSeconds);

        $result = (new FreshnessEvaluator)->evaluate($status, $lastHeard, 900, null, $now);

        $this->assertSame($expected, $result['state']);
        $this->assertSame($freshness, $result['freshness']);
        $this->assertSame($ageSeconds, $result['age_seconds']);
    }

    public function test_retired_wins_over_everything(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 12:00:00');
        $result = (new FreshnessEvaluator)->evaluate('active', $now, 900, $now->subDay(), $now);

        $this->assertSame(EquipmentState::Retired, $result['state']);
    }

    public function test_states_carry_tone_and_label(): void
    {
        $this->assertSame('ok', EquipmentState::Operating->tone());
        $this->assertSame('bad', EquipmentState::Offline->tone());
        $this->assertSame('warn', EquipmentState::Stale->tone());
        $this->assertSame('neutral', EquipmentState::Unknown->tone());
        $this->assertSame('Unknown', EquipmentState::Unknown->label());
    }
}
