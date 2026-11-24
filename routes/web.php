<?php

use App\Http\Controllers\DemoCreditPurchaseController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationHistoryController;
use App\Http\Controllers\GenerationImageController;
use App\Http\Controllers\GenerationQuoteController;
use App\Http\Controllers\GenerationStatusController;
use App\Http\Controllers\LatestGenerationController;
use App\Http\Controllers\RetryGenerationImagesController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('/studio', StudioController::class)->name('studio.index');

    Route::post('/wallet/demo-credits', DemoCreditPurchaseController::class)
        ->middleware('throttle:demo-credits')
        ->name('wallet.demo-credits.store');

    Route::post('/generations/quote', GenerationQuoteController::class)->name('generations.quote');
    Route::get('/generations/latest', LatestGenerationController::class)->name('generations.latest');
    Route::get('/generations/{generation}', GenerationStatusController::class)->whereNumber('generation')->name('generations.show');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations/{generation}/images/{index}', GenerationImageController::class)->whereNumber('index')->name('generations.images.show');
    Route::post('/generations/{generation}/images/retry', RetryGenerationImagesController::class)->whereNumber('generation')->name('generations.images.retry');
    Route::get('/generations', GenerationHistoryController::class)->name('generations.index');
});

require __DIR__.'/settings.php';
