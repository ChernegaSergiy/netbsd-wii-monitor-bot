<?php

namespace WiiMonitor\Service;

use WiiMonitor\Interfaces\DatabaseInterface;
use WiiMonitor\Interfaces\WebClientInterface;
use WiiMonitor\Interfaces\TelegramClientInterface;

class MonitoringService
{
    private DatabaseInterface $db;

    private WebClientInterface $webClient;

    private TelegramClientInterface $telegramClient;

    private TimestampService $timestampService;

    public function __construct(
        DatabaseInterface $db,
        WebClientInterface $webClient,
        TelegramClientInterface $telegramClient,
        TimestampService $timestampService
    ) {
        $this->db = $db;
        $this->webClient = $webClient;
        $this->telegramClient = $telegramClient;
        $this->timestampService = $timestampService;
    }

    public function processCheck(bool $force = false) : bool
    {
        echo '[ ' . date('H:i:s') . " ] Check started...\n";

        $checkUrl = $this->db->getSetting('check_url');
        $cacheFile = $this->db->getSetting('cache_file');
        $sourceTimezone = $this->db->getSetting('source_timezone');
        $targetTimezone = $this->db->getSetting('target_timezone');

        $viewportSettings = [
            'width' => (int) $this->db->getSetting('viewport_width'),
            'height' => (int) $this->db->getSetting('viewport_height'),
            'quality' => (int) $this->db->getSetting('image_quality'),
        ];

        // Get combined data
        $data = $this->webClient->getCombinedData($checkUrl, $viewportSettings);
        if (! $data) {
            echo "Failed to get data from web client.\n";

            return false;
        }

        // Extract timestamp
        $generatedOn = $this->timestampService->fetchGeneratedOn($data['content']);
        if (! $generatedOn) {
            echo "Generation timestamp not found in page content.\n";

            return false;
        }

        $lastGen = @file_get_contents($cacheFile);

        // Check if timestamp is recent and different
        if (! $force && ! $this->timestampService->isRecent($generatedOn, 30, $sourceTimezone) && $generatedOn !== $lastGen) {
            $convertedTime = $this->timestampService->convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);
            echo "Generation timestamp is not recent ({$convertedTime}), retrying in 5 minutes...\n";

            return false;
        }

        if ($generatedOn === $lastGen && ! $force) {
            echo "No new generation.\n";

            return true;
        }

        // Save new timestamp
        file_put_contents($cacheFile, $generatedOn);

        $convertedTime = $this->timestampService->convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);
        echo "Original timestamp: {$generatedOn}\n";
        echo "Converted timestamp: {$convertedTime}\n";

        // Save and send screenshot
        $imagePath = 'screenshot.jpg';
        if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
            $caption = sprintf(
                "New NetBSD Wii build:\nUTC: %s\nLocal: %s",
                $generatedOn,
                $convertedTime
            );

            $chatId = (int) $this->db->getSetting('chat_id');
            $success = $this->telegramClient->sendPhoto($chatId, $imagePath, $caption);

            if ($success) {
                echo "Screenshot sent with timestamp {$convertedTime}\n";
            } else {
                echo "Failed to send screenshot\n";
            }

            return $success;
        }

        echo "Failed to save screenshot.\n";

        return false;
    }
}
