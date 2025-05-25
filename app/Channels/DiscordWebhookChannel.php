<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordWebhookChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        $payload = $notification->toDiscordWebhook($notifiable);
        $webhookUrl = config('services.discord.notifications_webhook');

        if (!$webhookUrl) {
            Log::error('Discord webhook URL not configured for notifications.');
            return;
        }

        $response = Http::post($webhookUrl, $payload);

        if (!$response->successful()) {
            Log::error('Failed to send Discord notification.', ['status' => $response->status(), 'body' => $response->body()]);
        }
    }
}