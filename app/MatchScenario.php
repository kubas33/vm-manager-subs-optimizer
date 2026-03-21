<?php

namespace App;

use InvalidArgumentException;

final class MatchScenario
{
    /**
     * @param  array<int, array{our_score: int, opponent_score: int, actions: int}>  $sets
     */
    public function __construct(
        public string $label,
        public string $input,
        public array $sets,
    ) {}

    public static function fromInput(string $input, string $label): self
    {
        $normalizedInput = trim($input);

        if ($normalizedInput === '') {
            throw new InvalidArgumentException('Scenariusz nie może być pusty.');
        }

        $setStrings = preg_split('/\s*,\s*/', $normalizedInput);

        if ($setStrings === false || count($setStrings) < 3 || count($setStrings) > 5) {
            throw new InvalidArgumentException('Scenariusz musi zawierać od 3 do 5 setów.');
        }

        $sets = array_map(function (string $setString): array {
            if (! preg_match('/^(?<our>\d{1,2}):(?<opponent>\d{1,2})$/', trim($setString), $matches)) {
                throw new InvalidArgumentException('Każdy set musi mieć format `25:20`.');
            }

            $ourScore = (int) $matches['our'];
            $opponentScore = (int) $matches['opponent'];

            return [
                'our_score' => $ourScore,
                'opponent_score' => $opponentScore,
                'actions' => $ourScore + $opponentScore,
            ];
        }, $setStrings);

        return new self(
            label: $label,
            input: $normalizedInput,
            sets: $sets,
        );
    }

    public function setsCount(): int
    {
        return count($this->sets);
    }

    public function totalActions(): int
    {
        return array_sum(array_column($this->sets, 'actions'));
    }

    /**
     * @return array{label: string, input: string, sets: array<int, array{our_score: int, opponent_score: int, actions: int}>, sets_count: int, total_actions: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'input' => $this->input,
            'sets' => $this->sets,
            'sets_count' => $this->setsCount(),
            'total_actions' => $this->totalActions(),
        ];
    }
}
