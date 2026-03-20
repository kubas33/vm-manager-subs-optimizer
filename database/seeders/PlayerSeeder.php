<?php

namespace Database\Seeders;

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Database\Seeder;

class PlayerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Player::query()->exists()) {
            return;
        }

        $players = [
            ['name' => 'Jan Nowak', 'position' => PlayerPosition::Setter, 'training_bar' => 18, 'active' => true],
            ['name' => 'Piotr Zielinski', 'position' => PlayerPosition::Setter, 'training_bar' => 54, 'active' => true],
            ['name' => 'Marek Wisniewski', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 22, 'active' => true],
            ['name' => 'Pawel Wrobel', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 63, 'active' => true],
            ['name' => 'Kamil Jankowski', 'position' => PlayerPosition::OutsideHitter, 'training_bar' => 37, 'active' => true],
            ['name' => 'Tomasz Szymanski', 'position' => PlayerPosition::OutsideHitter, 'training_bar' => 71, 'active' => true],
            ['name' => 'Adam Lewandowski', 'position' => PlayerPosition::Opposite, 'training_bar' => 46, 'active' => true],
            ['name' => 'Lukasz Kaczmarek', 'position' => PlayerPosition::Opposite, 'training_bar' => 82, 'active' => true],
            ['name' => 'Michal Dabrowski', 'position' => PlayerPosition::Libero, 'training_bar' => 29, 'active' => true],
            ['name' => 'Patryk Krupa', 'position' => PlayerPosition::Libero, 'training_bar' => 88, 'active' => false],
        ];

        foreach ($players as $player) {
            Player::query()->create($player);
        }
    }
}
