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
            $this->logger->info('Custom keyboard attached to message');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Failed to send message: {$error}");

            return false;
        }

        if (200 !== $httpCode) {
            $this->logger->error("Message sending failed with HTTP code: {$httpCode}");

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

        if (! file_exists($imagePath)) {
            $this->logger->error("Image file not found: {$imagePath}");

            return false;
        }

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
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            $this->logger->error("CURL error while sending photo: {$error}");

            return false;
        }

        if (200 !== $httpCode) {
            $this->logger->error("Photo sending failed with HTTP code: {$httpCode}");

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
        $this->logger->info("Checking for updates from offset: {$offset}");

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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Failed to get updates: {$error}");

            return [];
        }

        if (200 !== $httpCode) {
            $this->logger->error("Updates request failed with HTTP code: {$httpCode}");

            return [];
        }

        $result = json_decode($response, true);
        if (! $result) {
            $this->logger->error('Invalid response from Telegram API');

            return [];
        }

        if (! empty($result['result'])) {
            $count = count($result['result']);
            $this->logger->success("Received {$count} new update(s)");
        } else {
            $this->logger->info('No new updates');
        }

        return $result;
    }
}
