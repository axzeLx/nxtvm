<?php

namespace App\Providers;

use App\Support\Fmt;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\VcScope::class);
        $this->app->scoped(\App\Support\Modules::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short alias for display helpers in Blade views
        AliasLoader::getInstance()->alias('Fmt', Fmt::class);
    }
}
