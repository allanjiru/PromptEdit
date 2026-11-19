<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationQuoteController;
use App\Http\Controllers\GenerationStatusController;
use App\Http\Controllers\GenerationImageController;



Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('/studio', 'studio/Index')->name('studio.index');

    Route::post('/generations/quote',GenerationQuoteController::class)->name('generations.quote');
    Route::get('/generations/{generation}',GenerationStatusController::class)->whereNumber('generation')->name('generations.show');
    Route::post('/generations', [GenerationController::class,'store',])->name('generations.store');
    Route::get('/generations/{generation}/images/{index}',GenerationImageController::class)->whereNumber('index')->name('generations.images.show');});

require __DIR__.'/settings.php';
