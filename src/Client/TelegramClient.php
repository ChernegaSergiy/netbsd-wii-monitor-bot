<?php

namespace WiiMonitor\Client;

use CURLFile;
use WiiMonitor\Service\ConsoleLogger;
use WiiMonitor\Interfaces\TelegramClientInterface;

class TelegramClient implements TelegramClientInterface
{
    private string $botToken;

    private ConsoleLogger $logger;

    public function __construct(string $botToken)
    {
        $this->botToken = $botToken;
        $this->logger = new ConsoleLogger;
    }

    public function sendMessage(int $chatId, string $text, ?string $keyboard = null) : mixed
    {
        $this->logger->info("Sending message to chat ID: {$chatId}");

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
            $this->logger->error("Failed to send message: {$error}");

            return false;
        }

        $result = json_decode($response, true);
        if (! $result || ! isset($result['ok']) || true !== $result['ok']) {
            $this->logger->error('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));

            return false;
        }

        $this->logger->success('Message sent successfully');

        return $result;
    }

    public function sendPhoto(int $chatId, string $imagePath, string $caption) : bool
    {
        $this->logger->info("Sending photo to chat ID: {$chatId}");

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
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Failed to send photo: {$error}");

            return false;
        }

        $result = json_decode($response, true);
        if (! $result || ! isset($result['ok']) || true !== $result['ok']) {
            $this->logger->error('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));

            return false;
        }

        $this->logger->success('Photo sent successfully');

        return true;
    }

    public function getUpdates(int $offset) : array
    {
        $this->logger->info("Getting updates from offset: {$offset}");

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
            $this->logger->error("Failed to get updates: {$error}");

            return [];
        }

        $result = json_decode($response, true);
        if (! $result || ! isset($result['ok']) || true !== $result['ok']) {
            $this->logger->error('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));

            return [];
        }

        if (! empty($result['result'])) {
            $this->logger->success('Received ' . count($result['result']) . ' update(s)');
        }

        return $result;
    }
}
