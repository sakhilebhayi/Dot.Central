<?php

namespace Tests\Feature;

use App\Jobs\SyncMinesConnection;
use App\Models\ControlRoom;
use App\Models\MinesConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncMinesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_one_job_per_usable_connection(): void
    {
        Queue::fake();
        $usable = MinesConnection::factory()->for(ControlRoom::factory())->create(['status' => 'verified']);
        MinesConnection::factory()->for(ControlRoom::factory())->create(['status' => 'disabled']);
        MinesConnection::factory()->for(ControlRoom::factory())->create(['status' => 'credential_invalid']);

        $this->artisan('mines:sync')->assertSuccessful();

        Queue::assertPushed(SyncMinesConnection::class, 1);
        Queue::assertPushed(SyncMinesConnection::class, fn (SyncMinesConnection $job) => $job->connectionId === $usable->id);
    }

    public function test_a_specific_connection_can_be_targeted(): void
    {
        Queue::fake();
        $a = MinesConnection::factory()->for(ControlRoom::factory())->create(['status' => 'verified']);
        MinesConnection::factory()->for(ControlRoom::factory())->create(['status' => 'verified']);

        $this->artisan('mines:sync', ['connection' => $a->id])->assertSuccessful();

        Queue::assertPushed(SyncMinesConnection::class, 1);
    }

    public function test_the_schedule_registers_the_sync(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('mines:sync');
    }
}
