<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ecosystem SSO handoff (issue I-08): a hub-issued token with the
 * ecosystem:read ability signs the user in once; anything else is refused.
 */
class EcosystemAuthTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    public function test_a_valid_handoff_token_signs_the_user_in_once_and_is_not_cached(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $plain = $user->createToken('hub-handoff', ['ecosystem:read'], now()->addMinutes(5))->plainTextToken;

        $response = $this->post(route('ecosystem.auth'), ['token' => $plain], $this->fromInfodot());

        $response->assertRedirect(route('dashboard'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->tokens()->count(), 'the handoff token is consumed');

        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => $plain], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_expired_wrong_ability_and_missing_tokens_are_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $expired = $user->createToken('hub-handoff', ['ecosystem:read'], now()->subMinute())->plainTextToken;
        $wrongAbility = $user->createToken('api', ['read'], now()->addMinutes(5))->plainTextToken;

        $this->post(route('ecosystem.auth'), ['token' => $expired], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => $wrongAbility], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => 'not-a-token'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(2, $user->tokens()->count(), 'refused tokens are left alone');
    }
}
