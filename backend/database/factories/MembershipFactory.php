<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory()->state(['role' => 'REPORTER']),
            'joined_at' => now(),
            'removed_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * State keanggotaan aktif.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'joined_at' => now(),
            'removed_at' => null,
        ]);
    }

    /**
     * State keanggotaan nonaktif.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'removed_at' => now(),
        ]);
    }
}
