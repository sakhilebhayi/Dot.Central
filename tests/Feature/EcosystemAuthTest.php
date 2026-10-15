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

    public function test_a_valid_handoff_token_signs_the_user_in_once_and_is_not_cached(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $plain = $user->createToken('hub-handoff', ['ecosystem:read'], now()->addMinutes(5))->plainTextToken;

        $response = $this->get('/auth/ecosystem?token='.$plain);

        $response->assertRedirect(route('dashboard'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->tokens()->count(), 'the handoff token is consumed');

        auth()->logout();
        $this->get('/auth/ecosystem?token='.$plain)->assertForbidden();
        $this->assertGuest();
    }

    public function test_expired_wrong_ability_and_missing_tokens_are_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $expired = $user->createToken('hub-handoff', ['ecosystem:read'], now()->subMinute())->plainTextToken;
        $wrongAbility = $user->createToken('api', ['read'], now()->addMinutes(5))->plainTextToken;

        $this->get('/auth/ecosystem?token='.$expired)->assertForbidden();
        $this->get('/auth/ecosystem?token='.$wrongAbility)->assertForbidden();
        $this->get('/auth/ecosystem')->assertForbidden();
        $this->get('/auth/ecosystem?token=not-a-token')->assertForbidden();
        $this->assertGuest();
        $this->assertSame(2, $user->tokens()->count(), 'refused tokens are left alone');
    }
}
