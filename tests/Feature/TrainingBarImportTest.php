<?php

use App\Models\Player;

beforeEach(function () {
    config()->set('services.vm_training_import.token', 'test-import-token');
});

test('training bar import updates players matched by vm player id', function () {
    $player = Player::factory()->withVmPlayerId(2060721)->create([
        'name' => 'Manso, Armindo',
        'training_bar' => 6,
    ]);

    $this->postJson(route('training-bars.import'), [
        'players' => [
            [
                'vm_player_id' => 2060721,
                'name' => 'Manso, Armindo',
                'training_bar' => 42,
            ],
        ],
    ], [
        'X-VM-Import-Token' => 'test-import-token',
    ])
        ->assertSuccessful()
        ->assertJson([
            'updated' => 1,
            'warnings' => [],
        ]);

    expect($player->fresh()->training_bar)->toBe(42);
});

test('training bar import warns about players missing from database without creating them', function () {
    Player::factory()->withVmPlayerId(2060721)->create([
        'training_bar' => 6,
    ]);

    $this->postJson(route('training-bars.import'), [
        'players' => [
            [
                'vm_player_id' => 2060721,
                'name' => 'Manso, Armindo',
                'training_bar' => 42,
            ],
            [
                'vm_player_id' => 2004528,
                'name' => 'Kwiatek, Kacper',
                'training_bar' => 1,
            ],
        ],
    ], [
        'X-VM-Import-Token' => 'test-import-token',
    ])
        ->assertSuccessful()
        ->assertJson([
            'updated' => 1,
            'warnings' => [
                [
                    'vm_player_id' => 2004528,
                    'name' => 'Kwiatek, Kacper',
                    'message' => 'Player not found in database.',
                ],
            ],
        ]);

    expect(Player::query()->where('vm_player_id', 2004528)->exists())->toBeFalse()
        ->and(Player::query()->count())->toBe(1);
});

test('training bar import rejects missing or invalid import token', function () {
    $payload = [
        'players' => [
            [
                'vm_player_id' => 2060721,
                'training_bar' => 42,
            ],
        ],
    ];

    $this->postJson(route('training-bars.import'), $payload)
        ->assertForbidden();

    $this->postJson(route('training-bars.import'), $payload, [
        'X-VM-Import-Token' => 'wrong-token',
    ])
        ->assertForbidden();
});

test('training bar import validates training bar range', function () {
    $this->postJson(route('training-bars.import'), [
        'players' => [
            [
                'vm_player_id' => 2060721,
                'training_bar' => 101,
            ],
        ],
    ], [
        'X-VM-Import-Token' => 'test-import-token',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('players.0.training_bar');
});
