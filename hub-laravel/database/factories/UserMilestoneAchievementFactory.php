<?php

namespace Database\Factories;

use App\Models\RevenueMilestone;
use App\Models\User;
use App\Models\UserMilestoneAchievement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserMilestoneAchievement>
 */
class UserMilestoneAchievementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'milestone_id' => RevenueMilestone::factory(),
            'total_at_achievement' => fake()->randomFloat(2, 1000, 100000),
            'achieved_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
