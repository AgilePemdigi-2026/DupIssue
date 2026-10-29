<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'invited_by' => User::factory()->state(['role' => 'MAINTAINER']),
            'reporter_id' => User::factory()->state(['role' => 'REPORTER']),
            'status' => 'PENDING',
            'created_at' => now(),
            'responded_at' => null,
        ];
    }

    /**
     * State status PENDING.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING',
            'responded_at' => null,
        ]);
    }

    /**
     * State status ACCEPTED.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ACCEPTED',
            'responded_at' => now(),
        ]);
    }

    /**
     * State status REJECTED.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'REJECTED',
            'responded_at' => now(),
        ]);
    }

    /**
     * State status CANCELED.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'CANCELED',
            'responded_at' => now(),
        ]);
    }
}
