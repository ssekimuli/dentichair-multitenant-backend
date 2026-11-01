<?php

namespace App\Providers;

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
        \Illuminate\Support\Facades\Gate::policy(\App\Models\Quotation::class, \App\Policies\QuotationPolicy::class);

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            function (\Illuminate\Mail\Events\MessageSent $event) {
                $recipients = array_keys($event->message->getTo());
                \App\Models\ActivityLog::log('send_email', 'Sent email to: ' . implode(', ', $recipients), ['subject' => $event->message->getSubject()]);
            }
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            function (\Illuminate\Auth\Events\Login $event) {
                \App\Models\ActivityLog::log('login', 'User logged in', ['email' => $event->user->email]);
            }
        );

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Logout::class,
            function (\Illuminate\Auth\Events\Logout $event) {
                if ($event->user) {
                    \App\Models\ActivityLog::log('logout', 'User logged out', ['email' => $event->user->email]);
                }
            }
        );
    }
}
