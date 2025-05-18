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
    }

    public function testCheck() : string
    {
        $testResult = $this->messages['test_results'];

        // Test page access and get data
        $checkUrl = $this->db->getSetting('check_url');
        $viewportSettings = [
            'width' => (int) $this->db->getSetting('viewport_width'),
            'height' => (int) $this->db->getSetting('viewport_height'),
            'quality' => (int) $this->db->getSetting('image_quality'),
        ];

        $data = $this->webClient->getCombinedData($checkUrl, $viewportSettings);

        if ($data) {
            $testResult .= $this->messages['page_accessible'] . "\n";

            // Test timestamp extraction
            $generatedOn = $this->timestampService->fetchGeneratedOn($data['content']);
            if ($generatedOn) {
                $testResult .= sprintf($this->messages['timestamp_found'], $generatedOn) . "\n";

                // Test timezone conversion
                $sourceTimezone = $this->db->getSetting('source_timezone');
                $targetTimezone = $this->db->getSetting('target_timezone');
                $convertedTime = $this->timestampService->convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);

                if ($convertedTime !== $generatedOn &&
                    false === strpos($convertedTime, 'conversion error') &&
                    false === strpos($convertedTime, 'conversion failed')
                ) {
                    $testResult .= sprintf($this->messages['timestamp_found'], $convertedTime) . "\n";
                } else {
                    $testResult .= sprintf($this->messages['timezone_conversion_failed'], $convertedTime) . "\n";
                }
            } else {
                $testResult .= $this->messages['timestamp_not_found'] . "\n";
            }

            // Test screenshot
            $testResult .= $this->messages['screenshot_captured'] . "\n";
            $imagePath = 'test_screenshot.jpg';
            if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
                // Test notification
                $testCaption = 'Test Notification';
                $chatId = (int) $this->db->getSetting('chat_id');
                $success = $this->telegramClient->sendPhoto($chatId, $imagePath, $testCaption);

                if ($success) {
                    $testResult .= $this->messages['test_notification_sent'] . "\n";
                } else {
                    $testResult .= $this->messages['test_notification_failed'] . "\n";
                }

                // Delete test file
                unlink($imagePath);
            } else {
                $testResult .= $this->messages['screenshot_failed'] . "\n";
            }
        } else {
            $testResult .= $this->messages['page_not_accessible'] . "\n";
        }

        return $testResult;
    }
}
