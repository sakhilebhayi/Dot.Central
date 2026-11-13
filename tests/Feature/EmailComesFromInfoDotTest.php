<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An InfoDot sign-in finds the local account by email. While a signed-in person could change their own email here,
 * unverified, they could set it to someone else's address and that person's next sign-in from InfoDot would land in
 * the changed account. The address belongs to the InfoDot account, so it is not editable here.
 */
class EmailComesFromInfoDotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_cannot_be_changed_from_the_profile_form(): void
    {
        $this->actingAs($user = User::factory()->create(['email' => 'mine@example.com']));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'New Name', 'email' => 'someone.else@example.com'])
            ->call('updateProfileInformation')
            ->assertHasErrors('email');

        $this->assertSame('mine@example.com', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_name_can_still_be_changed(): void
    {
        $this->actingAs($user = User::factory()->create(['email' => 'mine@example.com']));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'New Name', 'email' => 'mine@example.com'])
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_a_different_capitalisation_of_the_same_address_is_not_a_change(): void
    {
        $this->actingAs($user = User::factory()->create(['email' => 'mine@example.com']));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'New Name', 'email' => 'Mine@Example.com'])
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_one_person_cannot_capture_another_persons_infodot_sign_in(): void
    {
        $attacker = User::factory()->create(['email' => 'attacker@example.com']);
        $this->actingAs($attacker);

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'Attacker', 'email' => 'victim@example.com'])
            ->call('updateProfileInformation');

        $victim = app(ProvisionUserFromInfoDot::class)(['user' => ['name' => 'Victim', 'email' => 'victim@example.com', 'email_verified' => true]]);

        $this->assertNotNull($victim);
        $this->assertNotSame($attacker->id, $victim->id, 'the victim must get their own account, not the attacker\'s');
        $this->assertSame('attacker@example.com', $attacker->fresh()->email);
    }

    public function test_the_form_shows_the_email_as_fixed_and_says_where_to_change_it(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create(['email' => 'mine@example.com']));

        $html = $this->get('/user/profile')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="email"[^>]*readonly/', $html);
        $this->assertStringContainsString('comes from your InfoDot account', $html);
    }
}
