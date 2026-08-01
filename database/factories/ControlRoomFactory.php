<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ControlRoom>
 */
class ControlRoomFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->city().' Control Room';

        return [
            'team_id'        => Team::factory(),
            'name'           => $name,
            'slug'           => Str::slug($name).'-'.Str::random(6),
            'mines_site_ref' => Str::slug(fake()->city()),
            'is_active'      => true,
        ];
    }
}
