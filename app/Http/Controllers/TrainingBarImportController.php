<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportTrainingBarsRequest;
use App\Models\Player;
use Illuminate\Http\JsonResponse;

class TrainingBarImportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ImportTrainingBarsRequest $request): JsonResponse
    {
        /** @var array{players: list<array{vm_player_id: int, name?: string|null, training_bar: int}>} $validated */
        $validated = $request->validated();

        $vmPlayerIds = collect($validated['players'])
            ->pluck('vm_player_id')
            ->unique()
            ->values();

        $playersByVmId = Player::query()
            ->whereIn('vm_player_id', $vmPlayerIds)
            ->get()
            ->keyBy('vm_player_id');

        $updated = 0;
        $warnings = [];

        foreach ($validated['players'] as $importedPlayer) {
            /** @var Player|null $player */
            $player = $playersByVmId->get($importedPlayer['vm_player_id']);

            if ($player === null) {
                $warnings[] = [
                    'vm_player_id' => $importedPlayer['vm_player_id'],
                    'name' => $importedPlayer['name'] ?? null,
                    'message' => 'Player not found in database.',
                ];

                continue;
            }

            $player->forceFill([
                'training_bar' => $importedPlayer['training_bar'],
            ])->save();

            $updated++;
        }

        return response()->json([
            'updated' => $updated,
            'warnings' => $warnings,
        ]);
    }
}
