<?php

namespace App\Providers;

use App\Listeners\LogOutgoingHttpCall;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // Universal API call logging — covers every Http:: call site, no
        // per-integration wiring needed (see App\Listeners\LogOutgoingHttpCall).
        ResponseReceived::class => [
            LogOutgoingHttpCall::class . '@handleResponseReceived',
        ],
        ConnectionFailed::class => [
            LogOutgoingHttpCall::class . '@handleConnectionFailed',
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
