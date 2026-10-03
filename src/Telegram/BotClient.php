<?php

declare(strict_types=1);

namespace App\Telegram;

use CURLFile;

class BotClient
{
    private string $bot_token;

    public function __construct(string $bot_token)
    {
        $this->bot_token = $bot_token;
    }

    public function getUpdates(int $offset) : array
    {
        $url = "https://api.telegram.org/bot{$this->bot_token}/getUpdates?offset={$offset}&timeout=30";

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

    public function sendMessage(string $chat_id, string $text, ?array $keyboard = null) : array|false
    {
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";

        $post_data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if (null !== $keyboard) {
            $post_data['reply_markup'] = json_encode($keyboard);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post_data),
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

    public function sendPhoto(string $chat_id, string $image_path, string $caption) : bool
    {
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendPhoto";
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chat_id,
                'photo' => new CURLFile($image_path),
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || 200 !== $http_code) {
            error_log("Telegram send photo error: $error (HTTP $http_code) - Response: " . ($response ?: 'none'));
            return false;
        }

        return true;
    }

    public function editMessageText(string $chat_id, int $message_id, string $text, ?array $keyboard = null) : array|false
    {
        $url = "https://api.telegram.org/bot{$this->bot_token}/editMessageText";

        $post_data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if (null !== $keyboard) {
            $post_data['reply_markup'] = $keyboard;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($post_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Telegram edit message error: $error");
            return false;
        }

        return json_decode($response, true) ?? false;
    }
}
