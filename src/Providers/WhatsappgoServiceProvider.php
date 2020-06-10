<?php

namespace BotMan\Drivers\Whatsappgo\Providers;

use BotMan\Drivers\Whatsappgo\WhatsappgoDriver;
use Illuminate\Support\ServiceProvider;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Studio\Providers\StudioServiceProvider;

class WhatsappgoServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        if (! $this->isRunningInBotManStudio()) {
            $this->loadDrivers();

            $this->publishes([
                __DIR__.'/../../stubs/whatsappgo.php' => config_path('botman/whatsappgo.php'),
            ]);

            $this->mergeConfigFrom(__DIR__.'/../../stubs/whatsappgo.php', 'botman.whatsappgo');
        }
    }

    /**
     * Load BotMan drivers.
     */
    protected function loadDrivers()
    {
        DriverManager::loadDriver(WhatsappgoDriver::class);
    }

    /**
     * @return bool
     */
    protected function isRunningInBotManStudio()
    {
        return class_exists(StudioServiceProvider::class);
    }
}
