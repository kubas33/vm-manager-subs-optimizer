<?php

namespace App\Enums;

enum PlayerPosition: string
{
    case Setter = 'setter';
    case MiddleBlocker = 'middle_blocker';
    case OutsideHitter = 'outside_hitter';
    case Opposite = 'opposite';
    case Libero = 'libero';

    public function label(): string
    {
        return match ($this) {
            self::Setter => 'Rozgrywający',
            self::MiddleBlocker => 'Środkowy',
            self::OutsideHitter => 'Przyjmujący',
            self::Opposite => 'Atakujący',
            self::Libero => 'Libero',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $position) {
            $options[$position->value] = $position->label();
        }

        return $options;
    }
}
