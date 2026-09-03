<?php

namespace App\Providers;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

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
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Moments leave the API as `2026-09-01T10:00:00Z`. This hooks Carbon::jsonSerialize(),
        // i.e. a datetime attribute an API Resource returns as-is; Model::toArray() bypasses
        // it and would emit microseconds, which is why resources list their fields explicitly.
        // toIso8601ZuluString() converts to UTC on a copy, so the attribute itself stays intact.
        Date::serializeUsing(fn (CarbonInterface $date) => $date->toIso8601ZuluString());
    }
}
