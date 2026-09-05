<?php

namespace App\Providers;

use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Observers\BumpsTeamRevisionObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /* API-003: keep the per-team revision counter in sync with writes */
        Item::observe(BumpsTeamRevisionObserver::class);
        Status::observe(BumpsTeamRevisionObserver::class);
        Location::observe(BumpsTeamRevisionObserver::class);
        Label::observe(BumpsTeamRevisionObserver::class);
    }
}
