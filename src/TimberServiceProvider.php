<?php

namespace Spatie\LaravelTimber;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Timber\Client;

class TimberServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/timber.php' => config_path('timber.php'),
            ], 'config');
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/timber.php', 'timber');

        $this
            ->registerBindings()
            ->listenForLogEvents();
    }

    protected function registerBindings(): self
    {
        $this->app->bind(Client::class, function () {
            $timberConfig = config('timber');

            return new Client("http://localhost:{$timberConfig['port']}");
        });

        $this->app->bind(Timber::class, function () {
            $client = app(Client::class);

            return new Timber($client);
        });

        $this->app->singleton(QueryLogger::class, function () {
            return new QueryLogger();
        });

        return $this;
    }

    protected function listenForLogEvents(): self
    {
        Event::listen(MessageLogged::class, function (MessageLogged $message) {
            if (! config('timber.send_log_calls_to_timber')) {
                return $this;
            }

            /** @var Timber $timber */
            $timber = app(Timber::class);

            $timber->send($message->message);

            if ($message->level === 'error') {
                $timber->color('red');
            }

            if ($message->level === 'warning') {
                $timber->color('orange');
            }
        });

        return $this;
    }
}