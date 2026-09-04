<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportTrainingBarsRequest;
use App\TrainingBarImportService;
use Illuminate\Http\JsonResponse;

class TrainingBarImportController extends Controller
{
    public function __construct(private TrainingBarImportService $trainingBarImportService) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(ImportTrainingBarsRequest $request): JsonResponse
    {
        /** @var array{players: list<array{vm_player_id: int, name?: string|null, position?: string|null, training_bar: int, is_injured?: bool}>} $validated */
        $validated = $request->validated();

        return response()->json($this->trainingBarImportService->import($validated['players']));
    }
}
