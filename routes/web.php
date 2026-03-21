<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('players', 'pages::players.index')->name('players.index');
    Route::livewire('optimizer', 'pages::optimizer.create')->name('optimizer.create');
    Route::livewire('optimizer/result', 'pages::optimizer.result')->name('optimizer.result');
});

require __DIR__.'/settings.php';
