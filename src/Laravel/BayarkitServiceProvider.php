<?php

declare(strict_types=1);

namespace Bayarkit\Laravel;

use Bayarkit\Gateway;
use Bayarkit\Http\Transport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Binds `Bayarkit\Gateway` to the gateway named in `config/bayarkit.php` (`BAYARKIT_GATEWAY`), with credentials from
 * the environment. Changing gateway or keys is a config change, never a code change.
 */
final class BayarkitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/bayarkit.php', 'bayarkit');
        $this->app->singleton(Transport::class, fn (Application $app) => new LaravelTransport($app->make(Factory::class)));
        $this->app->singleton(GatewayManager::class, fn (Application $app) => new GatewayManager(
            (array) $app->make('config')->get('bayarkit', []),
            $app->make(Transport::class),
        ));
        $this->app->bind(Gateway::class, fn (Application $app) => $app->make(GatewayManager::class)->gateway());
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'bayarkit');
        if ($this->app->runningInConsole()) {
            $this->commands([ChipPublicKeyCommand::class]);
            $this->publishes([__DIR__.'/../../config/bayarkit.php' => config_path('bayarkit.php')], 'bayarkit-config');
            $this->publishes([__DIR__.'/../../lang' => $this->app->langPath('vendor/bayarkit')], 'bayarkit-lang');
        }
    }
}
