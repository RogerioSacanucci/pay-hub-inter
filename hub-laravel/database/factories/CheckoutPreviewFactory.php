<?php

namespace Database\Factories;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutPreview>
 */
class CheckoutPreviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'file_path' => 'previews/'.fake()->uuid().'.html',
        ];
    }
}
