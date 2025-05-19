<?php

namespace WiiMonitor\Service;

use WiiMonitor\Interfaces\DatabaseInterface;
use WiiMonitor\Interfaces\WebClientInterface;
use WiiMonitor\Interfaces\TelegramClientInterface;

class TestService
{
    private DatabaseInterface $db;

    private WebClientInterface $webClient;

    private TelegramClientInterface $telegramClient;

    private TimestampService $timestampService;

    private array $messages;

    private ConsoleLogger $logger;

    public function __construct(
        DatabaseInterface $db,
        WebClientInterface $webClient,
        TelegramClientInterface $telegramClient,
        TimestampService $timestampService,
        array $messages
    ) {
        $this->db = $db;
        $this->webClient = $webClient;
        $this->telegramClient = $telegramClient;
        $this->timestampService = $timestampService;
        $this->messages = $messages;
        $this->logger = new ConsoleLogger;
    }

    public function testCheck() : string
    {
        $this->logger->info('Starting test check procedure');
        $testResult = $this->messages['test_results'];

        // Test page access and get data
        $checkUrl = $this->db->getSetting('check_url');
        $this->logger->info("Testing URL access: {$checkUrl}");

        $viewportSettings = [
            'width' => (int) $this->db->getSetting('viewport_width'),
            'height' => (int) $this->db->getSetting('viewport_height'),
            'quality' => (int) $this->db->getSetting('image_quality'),
        ];

        $data = $this->webClient->getCombinedData($checkUrl, $viewportSettings);

        if ($data) {
            $this->logger->success('Page access test successful');
            $testResult .= $this->messages['page_accessible'] . "\n";

            // Test timestamp extraction
            $this->logger->info('Testing timestamp extraction');
            $generatedOn = $this->timestampService->fetchGeneratedOn($data['content']);
            if ($generatedOn) {
                $this->logger->success("Timestamp found: {$generatedOn}");
                $testResult .= sprintf($this->messages['timestamp_found'], $generatedOn) . "\n";

                // Test timezone conversion
                $this->logger->info('Testing timezone conversion');
                $sourceTimezone = $this->db->getSetting('source_timezone');
                $targetTimezone = $this->db->getSetting('target_timezone');
                $convertedTime = $this->timestampService->convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);

                if ($convertedTime !== $generatedOn &&
                    false === strpos($convertedTime, 'conversion error') &&
                    false === strpos($convertedTime, 'conversion failed')
                ) {
                    $this->logger->success("Timezone conversion successful: {$convertedTime}");
                    $testResult .= sprintf($this->messages['timestamp_found'], $convertedTime) . "\n";
                } else {
                    $this->logger->error("Timezone conversion failed: {$convertedTime}");
                    $testResult .= sprintf($this->messages['timezone_conversion_failed'], $convertedTime) . "\n";
                }
            } else {
                $this->logger->error('Timestamp not found in page content');
                $testResult .= $this->messages['timestamp_not_found'] . "\n";
            }

            // Test screenshot
            $this->logger->info('Testing screenshot capture');
            $testResult .= $this->messages['screenshot_captured'] . "\n";
            $imagePath = 'test_screenshot.jpg';
            if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
                $this->logger->success('Screenshot saved successfully');

                // Test notification
                $this->logger->info('Testing Telegram notification');
                $testCaption = 'Test Notification';
                $chatId = (int) $this->db->getSetting('chat_id');
                $success = $this->telegramClient->sendPhoto($chatId, $imagePath, $testCaption);

                if ($success) {
                    $this->logger->success('Test notification sent successfully');
                    $testResult .= $this->messages['test_notification_sent'] . "\n";
                } else {
                    $this->logger->error('Failed to send test notification');
                    $testResult .= $this->messages['test_notification_failed'] . "\n";
                }

                // Delete test file
                unlink($imagePath);
                $this->logger->info('Test screenshot cleaned up');
            } else {
                $this->logger->error('Failed to save screenshot');
                $testResult .= $this->messages['screenshot_failed'] . "\n";
            }
        } else {
            $this->logger->error('Page access test failed');
            $testResult .= $this->messages['page_not_accessible'] . "\n";
        }

        $this->logger->info('Test check procedure completed');

        return $testResult;
    }
}
