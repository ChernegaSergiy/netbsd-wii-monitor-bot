<?php

namespace WiiMonitor\Client;

use CURLFile;
use WiiMonitor\Interfaces\TelegramClientInterface;

class TelegramClient implements TelegramClientInterface
{
    private string $botToken;

    public function __construct(string $botToken)
    {
        $this->botToken = $botToken;
    }

    public function sendMessage(int $chatId, string $text, ?string $keyboard = null) : mixed
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        $postData = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if (null !== $keyboard) {
            $postData['reply_markup'] = $keyboard;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return false;
        }

        return json_decode($response, true);
    }

    public function sendPhoto(int $chatId, string $imagePath, string $caption) : bool
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendPhoto";
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'photo' => new CURLFile($imagePath),
                'caption' => $caption,
            ],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        if (false === $response) {
            return false;
        }

        $result = json_decode($response, true);

        return $result && isset($result['ok']) && true === $result['ok'];
    }

    public function getUpdates(int $offset) : array
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
}
