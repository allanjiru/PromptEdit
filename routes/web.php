<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\GenerationController;



Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::inertia('/studio', 'studio/Index')->name('studio.index');



    Route::post('/generations', [GenerationController::class,'store',])->name('generations.store');
});

require __DIR__.'/settings.php';
