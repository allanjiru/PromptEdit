<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pixelforge:recover-webhooks')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('pixelforge:recover-submissions')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('generations:recover-images --limit=50')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('pixelforge:reconcile-results --limit=10')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);
