<?php

namespace App;

use App\Models\Player;
use App\Packages\VmManagerApi\Services\VmManagerApiService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VmSubstitutionService
{
    private const MAX_SET_NUMBER = 5;

    /**
     * Fields that describe the behavior of a change. Set flags are handled
     * separately so records with a superset of the requested sets can match.
     *
     * @var list<string>
     */
    private const BEHAVIOR_FIELDS = [
        'matchType',
        'playerIn',
        'playerOut',
        'matchResult',
        'matchResultPoints',
        'matchState',
        'matchStatePoints',
        'winLostPoints',
        'reasonType',
        'fitness',
        'psyche',
        'failedActions',
        'failedActionsRunning',
        'returnReasonType',
        'returnFitness',
        'returnPsyche',
        'returnFailedActions',
        'returnActionsRunning',
        'returnFailedActionsRunning',
    ];

    /**
     * Values accepted by the tactics changes endpoint and by the result page.
     *
     * @var list<string>
     */
    private const MATCH_TYPES = [
        'League',
        'Cup',
        'IntCup',
        'Friendly',
    ];

    /**
     * Convert an optimizer plan into VM Manager substitution change payloads.
     *
     * The optimizer stores local database player IDs in the plan. Only players
     * that are currently available can be mapped to the VM IDs expected by the
     * remote endpoint.
     *
     * @param  array<string, mixed>  $plan
     * @return list<array{
     *     changeId: null,
     *     matchType: string,
     *     playerIn: int,
     *     playerOut: int,
     *     set1: int,
     *     set2: int,
     *     set3: int,
     *     set4: int,
     *     set5: int,
     *     matchResult: int,
     *     matchResultPoints: int,
     *     matchState: int,
     *     matchStatePoints: int,
     *     winLostPoints: int,
     *     reasonType: int,
     *     fitness: int,
     *     psyche: int,
     *     failedActions: int,
     *     failedActionsRunning: int,
     *     returnReasonType: int,
     *     returnFitness: int,
     *     returnPsyche: int,
     *     returnFailedActions: int,
     *     returnFailedActionsRunning: int,
     *     returnActionsRunning: int
     * }>
     */
    public function buildPayloads(array $plan, string $matchType = 'League'): array
    {
        $this->validateMatchType($matchType);

        $plan = $this->innerPlan($plan);
        $slots = $plan['slots'] ?? null;

        if ($slots === null) {
            throw new InvalidArgumentException('Plan zmian musi zawierać sloty.');
        }

        if (! is_array($slots)) {
            throw new InvalidArgumentException('Sloty planu zmian muszą być tablicą.');
        }

        /** @var list<array{local_out: int, local_in: int, set: int, activation: int}> $changes */
        $changes = [];
        $localPlayerIds = [];

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                throw new InvalidArgumentException('Każdy slot planu zmian musi być tablicą.');
            }

            $sets = $slot['sets'] ?? null;

            if ($sets === null) {
                continue;
            }

            if (! is_array($sets)) {
                throw new InvalidArgumentException('Zestawy w każdym slocie planu zmian muszą być tablicą.');
            }

            foreach ($sets as $set) {
                if (! is_array($set)) {
                    throw new InvalidArgumentException('Każdy zestaw planu zmian musi być tablicą.');
                }

                $substitutionPlayer = $set['substitution_player'] ?? null;

                if ($substitutionPlayer === null) {
                    continue;
                }

                $setNumber = $this->positiveInteger($set['set_number'] ?? null);

                if ($setNumber === null || $setNumber > self::MAX_SET_NUMBER) {
                    throw new InvalidArgumentException('Numery setów zmian muszą mieścić się w zakresie od 1 do 5.');
                }

                $starterPlayerId = $this->playerId($set['starter_player'] ?? null, 'starter_player');
                $substitutionPlayerId = $this->playerId($substitutionPlayer, 'substitution_player');

                if ($starterPlayerId === $substitutionPlayerId) {
                    throw new InvalidArgumentException('Zmiana musi wskazywać dwóch różnych zawodników.');
                }

                $activationPoint = $this->positiveInteger($set['activation_point'] ?? null);

                if ($activationPoint === null) {
                    throw new InvalidArgumentException('Rzeczywista zmiana musi zawierać prawidłowy punkt aktywacji.');
                }

                if ($activationPoint !== 1) {
                    throw new InvalidArgumentException('Integracja z VM Managerem obsługuje wyłącznie punkt aktywacji 1.');
                }

                $changes[] = [
                    'local_out' => $starterPlayerId,
                    'local_in' => $substitutionPlayerId,
                    'set' => $setNumber,
                    'activation' => $activationPoint,
                ];
                $localPlayerIds[] = $starterPlayerId;
                $localPlayerIds[] = $substitutionPlayerId;
            }
        }

        if ($changes === []) {
            return [];
        }

        $players = Player::query()
            ->available()
            ->whereKey(array_values(array_unique($localPlayerIds)))
            ->get()
            ->keyBy(fn (Player $player): string => (string) $player->getKey());

        $missingPlayerIds = collect(array_values(array_unique($localPlayerIds)))
            ->reject(fn (int $playerId): bool => $players->has((string) $playerId))
            ->values()
            ->all();

        if ($missingPlayerIds !== []) {
            throw new InvalidArgumentException('Każdy zawodnik zmiany musi być dostępnym zawodnikiem lokalnym.');
        }

        $vmPlayerIds = $players->mapWithKeys(
            fn (Player $player): array => [(string) $player->getKey() => $this->vmPlayerId($player)],
        );

        /** @var array<string, array{playerIn: int, playerOut: int, activation: int, sets: array<int, true>}> $grouped */
        $grouped = [];

        foreach ($changes as $change) {
            $playerOut = $vmPlayerIds->get((string) $change['local_out']);
            $playerIn = $vmPlayerIds->get((string) $change['local_in']);

            if (! is_int($playerOut) || ! is_int($playerIn) || $playerOut === $playerIn) {
                throw new InvalidArgumentException('Zawodnicy zmiany muszą mieć różne dodatnie ID VM.');
            }

            $key = $playerOut.':'.$playerIn.':'.$change['activation'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'playerIn' => $playerIn,
                    'playerOut' => $playerOut,
                    'activation' => $change['activation'],
                    'sets' => [],
                ];
            }

            $grouped[$key]['sets'][$change['set']] = true;
        }

        return array_values(array_map(
            fn (array $change): array => $this->payload(
                matchType: $matchType,
                playerIn: $change['playerIn'],
                playerOut: $change['playerOut'],
                activationPoint: $change['activation'],
                sets: $change['sets'],
            ),
            $grouped,
        ));
    }

    /**
     * Push all missing substitutions from an optimizer plan.
     *
     * Validation and local-player mapping happen before authentication or any
     * HTTP request. A failed POST stops the batch so the next run can fetch the
     * current list again and deduplicate safely.
     *
     * @param  array<string, mixed>  $plan
     * @return array{created: int, skipped: int, error: string|null}
     */
    public function pushPlan(array $plan, string $matchType = 'League'): array
    {
        try {
            $payloads = $this->buildPayloads($plan, $matchType);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage());
        } catch (Throwable) {
            return $this->failure('The substitution plan could not be prepared.');
        }

        if ($payloads === []) {
            return $this->result();
        }

        $api = app(VmManagerApiService::class);
        $timeout = $api->timeout();
        $lockSeconds = max(30, $timeout * (count($payloads) + 1));
        $lockKey = 'vm-substitution-sync:'.hash('sha256', session()->getId().'|'.$matchType);

        try {
            $lockedResult = Cache::lock($lockKey, $lockSeconds)->get(
                fn (): array => $this->pushPayloads(
                    payloads: $payloads,
                    matchType: $matchType,
                    api: $api,
                ),
            );
        } catch (Throwable) {
            return $this->failure('Nie udało się rozpocząć synchronizacji zmian z VM Managerem.');
        }

        if (! is_array($lockedResult)) {
            return $this->failure('Inna synchronizacja zmian z VM Managerem jest już uruchomiona.');
        }

        return [
            'created' => (int) ($lockedResult['created'] ?? 0),
            'skipped' => (int) ($lockedResult['skipped'] ?? 0),
            'error' => is_string($lockedResult['error'] ?? null) ? $lockedResult['error'] : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array{created: int, skipped: int, error: string|null}
     */
    private function pushPayloads(
        array $payloads,
        string $matchType,
        VmManagerApiService $api,
    ): array {
        try {
            $existing = $api->listTacticsChanges($matchType);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        } catch (Throwable) {
            return $this->failure('Nie udało się połączyć z VM Managerem podczas odczytu zmian.');
        }

        [$missingPayloads, $skipped] = $this->missingPayloads($payloads, $existing, $matchType);
        $created = 0;

        foreach ($missingPayloads as $payload) {
            try {
                $response = $api->createTacticsChange($payload);
            } catch (RuntimeException $exception) {
                return [
                    'created' => $created,
                    'skipped' => $skipped,
                    'error' => $exception->getMessage(),
                ];
            } catch (Throwable) {
                return [
                    'created' => $created,
                    'skipped' => $skipped,
                    'error' => 'Nie udało się połączyć z VM Managerem podczas zapisu zmiany.',
                ];
            }

            if (! $response->successful()) {
                return [
                    'created' => $created,
                    'skipped' => $skipped,
                    'error' => $this->httpError('Creating a substitution change', $response),
                ];
            }

            if ($this->responseReportsFailure($response)) {
                return [
                    'created' => $created,
                    'skipped' => $skipped,
                    'error' => 'VM Manager odrzucił zmianę.',
                ];
            }

            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<mixed>  $existing
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function missingPayloads(array $payloads, array $existing, string $matchType): array
    {
        /** @var array<string, array<int, true>> $existingSets */
        $existingSets = [];

        foreach ($existing as $change) {
            if (! is_array($change)) {
                continue;
            }

            $existingMatchType = $change['matchType'] ?? null;

            if (is_string($existingMatchType) && $existingMatchType !== $matchType) {
                continue;
            }

            $playerIn = $this->positiveInteger($change['playerIn'] ?? null);
            $playerOut = $this->positiveInteger($change['playerOut'] ?? null);
            $activationPoint = $this->positiveInteger($change['matchStatePoints'] ?? null);

            if ($playerIn === null || $playerOut === null || $activationPoint === null) {
                continue;
            }

            $key = $playerOut.':'.$playerIn.':'.$activationPoint;
            $existingSets[$key] ??= [];

            for ($setNumber = 1; $setNumber <= self::MAX_SET_NUMBER; $setNumber++) {
                if ($this->isEnabledFlag($change['set'.$setNumber] ?? null)) {
                    $existingSets[$key][$setNumber] = true;
                }
            }
        }

        $missingPayloads = [];
        $skipped = 0;

        foreach ($payloads as $payload) {
            $key = $payload['playerOut'].':'.$payload['playerIn'].':'.$payload['matchStatePoints'];
            $missingSets = [];

            for ($setNumber = 1; $setNumber <= self::MAX_SET_NUMBER; $setNumber++) {
                if (($payload['set'.$setNumber] ?? 0) === 1 && ! isset($existingSets[$key][$setNumber])) {
                    $missingSets[$setNumber] = true;
                }
            }

            if ($missingSets === []) {
                $skipped++;

                continue;
            }

            foreach (range(1, self::MAX_SET_NUMBER) as $setNumber) {
                $payload['set'.$setNumber] = isset($missingSets[$setNumber]) ? 1 : 0;
            }

            $missingPayloads[] = $payload;
        }

        return [$missingPayloads, $skipped];
    }

    /**
     * @param  array<int, true>  $sets
     * @return array<string, int|null|string>
     */
    private function payload(
        string $matchType,
        int $playerIn,
        int $playerOut,
        int $activationPoint,
        array $sets,
    ): array {
        return [
            'changeId' => null,
            'matchType' => $matchType,
            'playerIn' => $playerIn,
            'playerOut' => $playerOut,
            'set1' => isset($sets[1]) ? 1 : 0,
            'set2' => isset($sets[2]) ? 1 : 0,
            'set3' => isset($sets[3]) ? 1 : 0,
            'set4' => isset($sets[4]) ? 1 : 0,
            'set5' => isset($sets[5]) ? 1 : 0,
            'matchResult' => 0,
            'matchResultPoints' => 1,
            'matchState' => 1,
            'matchStatePoints' => $activationPoint,
            'winLostPoints' => 0,
            'reasonType' => 0,
            'fitness' => 0,
            'psyche' => 0,
            'failedActions' => 0,
            'failedActionsRunning' => 0,
            'returnReasonType' => 0,
            'returnFitness' => 0,
            'returnPsyche' => 0,
            'returnFailedActions' => 0,
            'returnFailedActionsRunning' => 0,
            'returnActionsRunning' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function innerPlan(array $plan): array
    {
        if (array_key_exists('plan', $plan)) {
            if (! is_array($plan['plan'])) {
                throw new InvalidArgumentException('Ranking musi zawierać wewnętrzny plan zmian.');
            }

            return $plan['plan'];
        }

        return $plan;
    }

    private function validateMatchType(string $matchType): void
    {
        if (! in_array($matchType, self::MATCH_TYPES, true)) {
            throw new InvalidArgumentException('Wybrany typ meczu nie jest obsługiwany przez VM Managera.');
        }
    }

    private function playerId(mixed $player, string $field): int
    {
        $rawId = $player instanceof Player
            ? $player->getKey()
            : (is_array($player) ? ($player['id'] ?? null) : null);
        $id = $this->positiveInteger($rawId);

        if ($id === null) {
            throw new InvalidArgumentException("Pole {$field} musi zawierać dodatnie lokalne ID zawodnika.");
        }

        return $id;
    }

    private function vmPlayerId(Player $player): int
    {
        $vmPlayerId = $this->positiveInteger($player->vm_player_id);

        if ($vmPlayerId === null) {
            throw new InvalidArgumentException('Każdy zawodnik zmiany musi mieć dodatnie ID VM.');
        }

        return $vmPlayerId;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        return null;
    }

    private function isEnabledFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value === 1;
        }

        return is_string($value) && in_array($value, ['1', 'true'], true);
    }

    private function responseReportsFailure(Response $response): bool
    {
        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('success', $json)) {
            return false;
        }

        return in_array($json['success'], [false, 0, '0', 'false'], true);
    }

    private function httpError(string $operation, Response $response): string
    {
        return $operation.' zakończone kodem HTTP '.$response->status().'.';
    }

    /**
     * @return array{created: int, skipped: int, error: null}
     */
    private function result(): array
    {
        return [
            'created' => 0,
            'skipped' => 0,
            'error' => null,
        ];
    }

    /**
     * @return array{created: int, skipped: int, error: string}
     */
    private function failure(string $error): array
    {
        return [
            'created' => 0,
            'skipped' => 0,
            'error' => $error,
        ];
    }
}
