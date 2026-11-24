<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    protected function configureTrustedProxies(): void
    {
        $configured = config('trustedproxy.proxies', []);

        if (! is_array($configured)) {
            return;
        }

        $proxies = array_values(array_filter(
            $configured,
            static fn (mixed $proxy): bool => is_string($proxy)
                && $proxy !== ''
        ));

        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('demo-credits', function (Request $request): Limit {
            $attempts = max(
                1,
                (int) config('demo_credits.rate_limit_per_minute', 10)
            );

            return Limit::perMinute($attempts)->by(
                'demo-credits:'.$request->user()?->getAuthIdentifier().'|'.$request->ip()
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
