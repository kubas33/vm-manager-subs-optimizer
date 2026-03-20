<?php

use App\Enums\PlayerPosition;

test('player positions expose labels and options for ui selects', function () {
    expect(PlayerPosition::Setter->label())->toBe('Rozgrywajacy')
        ->and(PlayerPosition::MiddleBlocker->label())->toBe('Srodkowy')
        ->and(PlayerPosition::options())->toBe([
            'setter' => 'Rozgrywajacy',
            'middle_blocker' => 'Srodkowy',
            'outside_hitter' => 'Przyjmujacy',
            'opposite' => 'Atakujacy',
            'libero' => 'Libero',
        ]);
});
