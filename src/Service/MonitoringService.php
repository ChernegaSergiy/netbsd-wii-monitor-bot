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

    private ConsoleLogger $logger;

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
        $this->logger = new ConsoleLogger;
    }

    public function processCheck(bool $force = false) : bool
    {
        $this->logger->info('Starting check process...');

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
        $this->logger->info('Fetching data from web client...');
        $data = $this->webClient->getCombinedData($checkUrl, $viewportSettings);

        if (! $data) {
            $this->logger->error('Failed to get data from web client');

            return false;
        }

        $this->logger->success('Data received successfully');

        // Extract timestamp
        $this->logger->info('Extracting timestamp...');
        $generatedOn = $this->timestampService->fetchGeneratedOn($data['content']);

        if (! $generatedOn) {
            $this->logger->error('Generation timestamp not found in page content');

            return false;
        }

        $this->logger->success('Timestamp extracted: ' . $generatedOn);

        $lastGen = @file_get_contents($cacheFile);

        // Check if timestamp is recent and different
        if (! $force && ! $this->timestampService->isRecent($generatedOn, 30, $sourceTimezone) && $generatedOn !== $lastGen) {
            $convertedTime = $this->timestampService->convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);
            $this->logger->warning("Generation timestamp is not recent ({$convertedTime}), will retry in 5 minutes");

            return false;
        }

        if ($generatedOn === $lastGen && ! $force) {
            $this->logger->info('No new generation detected');

            return true;
        }

        // Save new timestamp
        file_put_contents($cacheFile, $generatedOn);

        $convertedTime = $this->timestampService->convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);
        $this->logger->info("Original timestamp: {$generatedOn}");
        $this->logger->info("Converted timestamp: {$convertedTime}");

        // Save and send screenshot
        $imagePath = 'screenshot.jpg';
        $this->logger->info('Saving screenshot...');

        if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
            $caption = sprintf(
                "New NetBSD Wii build:\nUTC: %s\nLocal: %s",
                $generatedOn,
                $convertedTime
            );

            $this->logger->info('Sending screenshot to Telegram...');
            $chatId = (int) $this->db->getSetting('chat_id');
            $success = $this->telegramClient->sendPhoto($chatId, $imagePath, $caption);

            if ($success) {
                $this->logger->success("Screenshot sent with timestamp {$convertedTime}");
            } else {
                $this->logger->error('Failed to send screenshot');
            }

            return $success;
        }

        $this->logger->error('Failed to save screenshot');

        return false;
    }
}
