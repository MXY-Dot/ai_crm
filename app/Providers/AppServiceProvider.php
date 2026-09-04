<?php

namespace App\Providers;

use App\Support\Messaging\MessageLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Super-admin messaging audit/kill-switch (see MessageLogger) --
        // hooked here instead of at each of the ~6 places the app sends
        // mail (Mailables, notifications, raw Mail::to()) so every one of
        // them is covered automatically, with no per-call-site changes.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $to = collect($event->message->getTo())->first()?->getAddress();
            if (! $to) {
                return;
            }

            $tenantId = MessageLogger::tenantIdForRecipient($to);

            if (! MessageLogger::isChannelEnabled($tenantId, 'email')) {
                MessageLogger::log($tenantId, 'mail', $to, $event->message->getSubject(), 'blocked');

                return false;
            }
        });

        Event::listen(MessageSent::class, function (MessageSent $event) {
            $to = collect($event->message->getTo())->first()?->getAddress();
            if (! $to) {
                return;
            }

            $tenantId = MessageLogger::tenantIdForRecipient($to);
            MessageLogger::log($tenantId, 'mail', $to, $event->message->getSubject(), 'sent');
        });
    }
}
