<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * The email address is not editable here. An InfoDot sign-in finds the local account by email, so a person who
     * could change their own address, unverified, could set it to someone else's and receive that person's next
     * sign-in. The address belongs to the InfoDot account and is changed there.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ])->after(function ($validator) use ($user, $input) {
            $sent = Str::lower(trim((string) ($input['email'] ?? '')));

            if ($sent !== '' && $sent !== Str::lower((string) $user->email)) {
                $validator->errors()->add('email', 'Your email comes from your InfoDot account. Change it there, then sign in again.');
            }
        })->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        $user->forceFill(['name' => $input['name']])->save();
    }
}
