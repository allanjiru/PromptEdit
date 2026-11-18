<?php

use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;



Route::post('/webhooks/pixelforge',PixelforgeWebhookController::class)->name('webhooks.pixelforge');