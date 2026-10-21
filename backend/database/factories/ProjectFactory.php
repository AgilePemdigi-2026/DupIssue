<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->state(['role' => 'MAINTAINER']),
            'name' => fake()->unique()->lexify('Project ??????'),
            'description' => fake()->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
