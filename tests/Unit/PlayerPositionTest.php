<?php

use App\Enums\PlayerPosition;

test('player positions expose labels and options for ui selects', function () {
    expect(PlayerPosition::Setter->label())->toBe('Rozgrywający')
        ->and(PlayerPosition::MiddleBlocker->label())->toBe('Środkowy')
        ->and(PlayerPosition::options())->toBe([
            'setter' => 'Rozgrywający',
            'middle_blocker' => 'Środkowy',
            'outside_hitter' => 'Przyjmujący',
            'opposite' => 'Atakujący',
            'libero' => 'Libero',
        ]);
});
