<?php

namespace App\Providers;

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

use DateTime;
use Event;
use File;
use Log;


class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'App\Events\SomeEvent' => [
            'App\Listeners\EventListener',
        ],
    ];

    /**
     * Register any other events for your application.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function boot(DispatcherContract $events)
    {
        parent::boot($events);

         //if (\App::environment() === 'local' || \App::environment() === 'dev') {

         $path = storage_path().'/logs/query.log';

         Event::listen('illuminate.query', function($sql, $bindings, $time) use($path) {
              // Uncomment this if you want to include bindings to queries
              $sql = str_replace(array('%', '?'), array('%%', "'%s'"), $sql);
              $sql = vsprintf($sql, $bindings);
              $time_now = (new DateTime)->format('Y-m-d H:i:s');;
              $log = $time_now.' | '.$sql.' | '.$time.'ms'.PHP_EOL;
                   File::append($path, $log);
          });
        //}

        //
    }
}
