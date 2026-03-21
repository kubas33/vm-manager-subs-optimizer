<?php

namespace App;

use InvalidArgumentException;

final class ScenarioSet
{
    /**
     * @param  array<int, MatchScenario>  $scenarios
     */
    public function __construct(
        public array $scenarios,
    ) {
        if ($this->scenarios === []) {
            throw new InvalidArgumentException('Wpisz co najmniej jeden scenariusz.');
        }
    }

    public static function single(MatchScenario $scenario): self
    {
        return new self([$scenario]);
    }

    /**
     * @param  array<int, string>  $inputs
     */
    public static function fromInputs(array $inputs, string $labelPrefix = 'Scenariusz'): self
    {
        if ($inputs === []) {
            throw new InvalidArgumentException('Wpisz co najmniej jeden scenariusz.');
        }

        $scenarios = array_map(
            fn (string $input, int $index): MatchScenario => MatchScenario::fromInput($input, $labelPrefix.' '.($index + 1)),
            array_values($inputs),
            array_keys($inputs),
        );

        return new self($scenarios);
    }

    public function count(): int
    {
        return count($this->scenarios);
    }

    public function totalActions(): int
    {
        return array_sum(array_map(
            fn (MatchScenario $scenario): int => $scenario->totalActions(),
            $this->scenarios,
        ));
    }

    /**
     * @return array<int, array{label: string, input: string, sets: array<int, array{our_score: int, opponent_score: int, actions: int}>, sets_count: int, total_actions: int}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (MatchScenario $scenario): array => $scenario->toArray(),
            $this->scenarios,
        );
    }
}
