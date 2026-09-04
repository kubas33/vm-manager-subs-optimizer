<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TrainingBarImportService
{
    /**
     * @var array<string, PlayerPosition>
     */
    private const VM_POSITION_MAP = [
        'A' => PlayerPosition::Opposite,
        'L' => PlayerPosition::Libero,
        'P' => PlayerPosition::OutsideHitter,
        'R' => PlayerPosition::Setter,
        'S' => PlayerPosition::MiddleBlocker,
    ];

    /**
     * Import training bars from the configured VM Manager endpoint.
     *
     * @return array{updated: int, created: int, warnings: list<array{vm_player_id: int|null, name: string|null, message: string}>}
     */
    public function importFromVmManager(): array
    {
        $url = config('services.vm_training_import.url');
        $token = config('services.vm_training_import.api_token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('VM Manager import is not configured.');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout((int) config('services.vm_training_import.timeout', 20))
            ->get($url);

        $response->throw();

        $players = $response->json('players');

        if (! is_array($players)) {
            throw new RuntimeException('VM Manager returned an invalid players payload.');
        }

        return $this->import(array_values(array_filter(
            $players,
            static fn (mixed $player): bool => is_array($player),
        )));
    }

    /**
     * Import normalized or VM Manager-shaped player records.
     *
     * @param  array<int, array<string, mixed>>  $players
     * @return array{updated: int, created: int, warnings: list<array{vm_player_id: int|null, name: string|null, message: string}>}
     */
    public function import(array $players): array
    {
        $normalizedPlayers = [];
        $warnings = [];

        foreach ($players as $player) {
            $normalizedPlayer = $this->normalizePlayer($player);

            if ($normalizedPlayer['vm_player_id'] === null) {
                $warnings[] = [
                    'vm_player_id' => null,
                    'name' => $normalizedPlayer['name'],
                    'message' => 'Player ID is missing or invalid.',
                ];

                continue;
            }

            $normalizedPlayers[(string) $normalizedPlayer['vm_player_id']] = $normalizedPlayer;
        }

        return DB::transaction(function () use ($normalizedPlayers, $warnings): array {
            $vmPlayerIds = array_map(
                static fn (array $player): int => $player['vm_player_id'],
                array_values($normalizedPlayers),
            );

            $playersByVmId = Player::query()
                ->whereIn('vm_player_id', $vmPlayerIds)
                ->get()
                ->keyBy(fn (Player $player): string => (string) $player->vm_player_id);

            $updated = 0;
            $created = 0;

            foreach ($normalizedPlayers as $normalizedPlayer) {
                if ($normalizedPlayer['training_bar'] === null) {
                    $warnings[] = [
                        'vm_player_id' => $normalizedPlayer['vm_player_id'],
                        'name' => $normalizedPlayer['name'],
                        'message' => 'Player could not be imported because training_bar is missing or invalid.',
                    ];

                    continue;
                }

                /** @var Player|null $player */
                $player = $playersByVmId->get((string) $normalizedPlayer['vm_player_id']);

                if ($player !== null) {
                    $player->update([
                        'training_bar' => $normalizedPlayer['training_bar'],
                        'is_injured' => $normalizedPlayer['is_injured'],
                    ]);
                    $updated++;

                    continue;
                }

                $missingFields = [];

                if ($normalizedPlayer['name'] === null) {
                    $missingFields[] = 'name';
                }

                if ($normalizedPlayer['position'] === null) {
                    $missingFields[] = 'position';
                }

                if ($missingFields !== []) {
                    $warnings[] = [
                        'vm_player_id' => $normalizedPlayer['vm_player_id'],
                        'name' => $normalizedPlayer['name'],
                        'message' => 'Player could not be created because '.implode(', ', $missingFields).' is missing or invalid.',
                    ];

                    continue;
                }

                $player = Player::query()->create([
                    'vm_player_id' => $normalizedPlayer['vm_player_id'],
                    'name' => $normalizedPlayer['name'],
                    'position' => $normalizedPlayer['position'],
                    'training_bar' => $normalizedPlayer['training_bar'],
                    'active' => true,
                    'is_injured' => $normalizedPlayer['is_injured'],
                ]);

                $playersByVmId->put((string) $player->vm_player_id, $player);
                $created++;
            }

            return [
                'updated' => $updated,
                'created' => $created,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $player
     * @return array{vm_player_id: int|null, name: string|null, position: PlayerPosition|null, training_bar: int|null, is_injured: bool}
     */
    private function normalizePlayer(array $player): array
    {
        $vmPlayerId = $player['vm_player_id'] ?? $player['playerId'] ?? null;
        $vmPlayerId = is_numeric($vmPlayerId) && (int) $vmPlayerId > 0 ? (int) $vmPlayerId : null;

        $name = is_string($player['name'] ?? null) ? trim($player['name']) : null;
        $firstName = is_string($player['fstName'] ?? null) ? trim($player['fstName']) : '';

        if ($name !== null && $name !== '' && $firstName !== '' && ! str_contains($name, ',')) {
            $name .= ', '.$firstName;
        }

        $name = $name === '' ? null : $name;

        $position = $this->normalizePosition($player['position'] ?? $player['pozycja'] ?? null);

        $trainingBar = $player['training_bar'] ?? $player['trainingPoints'] ?? null;
        $trainingBar = is_numeric($trainingBar) && (int) $trainingBar >= 0 && (int) $trainingBar <= 100
            ? (int) $trainingBar
            : null;
        $isInjured = $this->normalizeBoolean($player['is_injured'] ?? $player['isInjured'] ?? false);

        return [
            'vm_player_id' => $vmPlayerId,
            'name' => $name,
            'position' => $position,
            'training_bar' => $trainingBar,
            'is_injured' => $isInjured,
        ];
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    private function normalizePosition(mixed $position): ?PlayerPosition
    {
        if ($position instanceof PlayerPosition) {
            return $position;
        }

        if (! is_string($position)) {
            return null;
        }

        $position = trim($position);

        return self::VM_POSITION_MAP[strtoupper($position)]
            ?? PlayerPosition::tryFrom($position);
    }
}
