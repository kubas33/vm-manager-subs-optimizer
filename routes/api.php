<?php

use App\Http\Controllers\TrainingBarImportController;
use Illuminate\Support\Facades\Route;

Route::post('training-bars/import', TrainingBarImportController::class)
    ->name('training-bars.import');
