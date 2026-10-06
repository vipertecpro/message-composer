<?php

namespace Vipertecpro\MessageComposer;

use Illuminate\Support\ServiceProvider;
use Vipertecpro\MessageComposer\Commands\ConfigureCommand;

class MessageComposerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessageComposer::class, function () {
            return new MessageComposer;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureCommand::class,
            ]);
        }
    }
}
