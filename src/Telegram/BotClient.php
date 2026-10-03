<?php

declare(strict_types=1);

namespace App\Telegram;

use CURLFile;

class BotClient
{
    private string $botToken;

    public function __construct(string $botToken)
    {
        $this->botToken = $botToken;
    }

    public function getUpdates(int $offset): array
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getUpdates?offset={$offset}&timeout=30";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Telegram API error: $error");
            return [];
        }

        return json_decode($response, true) ?? [];
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): array|false
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        $postData = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if (null !== $keyboard) {
            $postData['reply_markup'] = json_encode($keyboard);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Telegram send message error: $error");
            return false;
        }

        return json_decode($response, true) ?? false;
    }

    public function sendPhoto(string $chatId, string $imagePath, string $caption): bool
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendPhoto";
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'photo' => new CURLFile($imagePath),
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("Telegram send photo error: $error (HTTP $httpCode) - Response: " . ($response ?: 'none'));
            return false;
        }

        return true;
    }
}
