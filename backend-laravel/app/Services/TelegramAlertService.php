<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramAlertService
{
    public function send(string $message): bool
    {
        if (! config('services.telegram.enabled')) {
            return false;
        }

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return false;
        }

        $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        return $response->successful();
    }
}
