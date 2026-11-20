<?php

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::policy(\App\Models\Quotation::class, \App\Policies\QuotationPolicy::class);

        Event::listen(
            \Stancl\Tenancy\Events\TenancyInitialized::class,
            \Stancl\Tenancy\Listeners\BootstrapTenancy::class
        );

        Event::listen(
            \Stancl\Tenancy\Events\TenancyEnded::class,
            \Stancl\Tenancy\Listeners\RevertToCentralContext::class
        );

        \Illuminate\Support\Facades\View::composer('emails.*', function ($view): void {
            $tenant = app(TenantContext::class)->current();

            $view->with('tenantBranding', [
                'display_name' => $tenant?->display_name ?? config('app.name'),
                'app_url' => $tenant?->application_url ?? config('app.url'),
                'logo_url' => $tenant?->logo_url,
                'brand_color' => $tenant?->brand_color ?? '#2563eb',
                'support_email' => $tenant?->support_email,
                'support_phone' => $tenant?->support_phone,
            ]);
        });

        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            function (\Illuminate\Mail\Events\MessageSending $event): void {
                $tenant = app(TenantContext::class)->current();

                if (!$tenant) {
                    return;
                }

                $from = $event->message->getFrom();
                $fromAddress = isset($from[0]) ? $from[0]->getAddress() : config('mail.from.address');
                $fromName = $tenant->email_from_name ?: $tenant->display_name;

                if ($fromAddress) {
                    $event->message->from(new Address($fromAddress, $fromName));
                }

                if ($tenant->email_reply_to) {
                    $event->message->replyTo(new Address($tenant->email_reply_to));
                }
            }
        );

        Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            function (\Illuminate\Mail\Events\MessageSent $event) {
                $recipients = array_keys($event->message->getTo());
                \App\Models\ActivityLog::log('send_email', 'Sent email to: ' . implode(', ', $recipients), ['subject' => $event->message->getSubject()]);
            }
        );

        Event::listen(
            \Illuminate\Auth\Events\Login::class,
            function (\Illuminate\Auth\Events\Login $event) {
                \App\Models\ActivityLog::log('login', 'User logged in', ['email' => $event->user->email]);
            }
        );

        Event::listen(
            \Illuminate\Auth\Events\Logout::class,
            function (\Illuminate\Auth\Events\Logout $event) {
                if ($event->user) {
                    \App\Models\ActivityLog::log('logout', 'User logged out', ['email' => $event->user->email]);
                }
            }
        );

        Event::listen(\Stancl\Tenancy\Events\MigratingDatabase::class, function ($event): void {
            $event->tenant->update([
                'migration_status' => 'running',
                'migration_error' => null,
            ]);
        });

        Event::listen(\Stancl\Tenancy\Events\DatabaseMigrated::class, function ($event): void {
            $tenant = $event->tenant;

            $tenant->update([
                'status' => $tenant->status === 'provisioning' ? 'active' : $tenant->status,
                'migration_status' => 'succeeded',
                'last_migrated_at' => now(),
                'migration_error' => null,
            ]);
        });
    }
}
