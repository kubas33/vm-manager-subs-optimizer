<?php

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vm_player_id' => null,
            'name' => fake()->firstName().' '.fake()->lastName(),
            'position' => fake()->randomElement(PlayerPosition::cases()),
            'training_bar' => fake()->numberBetween(0, 100),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    public function forPosition(PlayerPosition $position): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => $position,
        ]);
    }

    public function fullyTrained(): static
    {
        return $this->state(fn (array $attributes) => [
            'training_bar' => 100,
        ]);
    }

    public function withVmPlayerId(int $vmPlayerId): static
    {
        return $this->state(fn (array $attributes) => [
            'vm_player_id' => $vmPlayerId,
        ]);
    }
}
