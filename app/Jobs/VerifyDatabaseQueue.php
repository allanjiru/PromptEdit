<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class VerifyDatabaseQueue implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('PromptEdit database queue integration successful', [
            'executed_at' => now()->toIso8601String(),
        ]);
    }
}