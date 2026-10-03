<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Config\SettingsManager;
use App\Constants\Messages;
use App\Telegram\BotClient;
use App\Utils\TimeManager;

class Checker
{
    private SettingsManager $settings;
    private PuppeteerClient $puppeteer;
    private HtmlParser $parser;
    private TimeManager $timeManager;
    private BotClient $botClient;

    public function __construct(
        SettingsManager $settings,
        PuppeteerClient $puppeteer,
        HtmlParser $parser,
        TimeManager $timeManager,
        BotClient $botClient
    ) {
        $this->settings = $settings;
        $this->puppeteer = $puppeteer;
        $this->parser = $parser;
        $this->timeManager = $timeManager;
        $this->botClient = $botClient;
    }

    public function processCheck(bool $force = false, int $attempt = 1): bool
    {
        echo '[ ' . date('H:i:s') . " ] Check started…\n";

        $checkUrl = $this->settings->get('check_url');
        $cacheFile = $this->settings->get('cache_file');
        
        $data = $this->puppeteer->getCombinedData($checkUrl);
        if (! $data) {
            echo "Failed to get data from Puppeteer server.\n";
            return false;
        }

        if (! $this->parser->isScreenshotComplete($data['content'])) {
            echo "Screenshot is incomplete, scheduling retry in 1 minute…\n";
            sleep(60);
            return $this->processCheck($force, $attempt);
        }

        $generatedOn = $this->parser->fetchGeneratedOn($data['content']);
        if (! $generatedOn) {
            echo "Generation timestamp not found in page content.\n";
            return false;
        }

        $lastGen = @file_get_contents($cacheFile);

        if (! $force && ! $this->timeManager->isRecent($generatedOn, 30) && $generatedOn !== $lastGen) {
            $convertedTime = $this->timeManager->convertTimezone($generatedOn);
            echo "Generation timestamp is not recent ({$convertedTime}), retrying in 5 minutes…\n";
            return false;
        }

        if ($generatedOn === $lastGen && ! $force) {
            if ($attempt < 3) {
                echo "No new generation (attempt $attempt). Waiting 30s to see if NetBSD is just slow...\n";
                sleep(30);
                return $this->processCheck($force, $attempt + 1);
            }
            
            echo "No new generation after 3 attempts.\n";
            return true;
        }

        file_put_contents($cacheFile, $generatedOn);

        $convertedTime = $this->timeManager->convertTimezone($generatedOn);
        echo "Original timestamp: {$generatedOn}\n";
        echo "Converted timestamp: {$convertedTime}\n";

        $imagePath = 'screenshot.jpg';
        if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
            $caption = sprintf(
                "New NetBSD Wii build:\nUTC: %s\nLocal: %s",
                $generatedOn,
                $convertedTime
            );

            $chatId = $this->settings->get('chat_id');
            $success = $this->botClient->sendPhoto($chatId, $imagePath, $caption);
            
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

    public function testCheck(string $chatId): string
    {
        $initialMessageText = Messages::get('test_starting');
        $sentMessage = $this->botClient->sendMessage($chatId, $initialMessageText);

        $messageId = null;
        if ($sentMessage && isset($sentMessage['result']['message_id'])) {
            $messageId = $sentMessage['result']['message_id'];
        } else {
            error_log("Failed to send initial test message to chat ID: {$chatId}");
            return $this->performFullTestAndReturnResult();
        }

        $statusUpdates = [];
        $resultHeader = Messages::get('test_results_header');

        $checkUrl = $this->settings->get('check_url');
        $data = $this->puppeteer->getCombinedData($checkUrl);

        if ($data) {
            $statusUpdates[] = Messages::get('page_accessible');
            $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));

            $generatedOn = $this->parser->fetchGeneratedOn($data['content']);
            if ($generatedOn) {
                $statusUpdates[] = sprintf(Messages::get('timestamp_found'), $generatedOn);
                $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));

                $convertedTime = $this->timeManager->convertTimezone($generatedOn);

                if ($convertedTime !== $generatedOn &&
                    false === strpos($convertedTime, 'conversion error') &&
                    false === strpos($convertedTime, 'conversion failed')) {
                    $statusUpdates[] = sprintf(Messages::get('timestamp_found'), $convertedTime);
                } else {
                    $statusUpdates[] = sprintf(Messages::get('timezone_conversion_failed'), $convertedTime);
                }
                $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));
            } else {
                $statusUpdates[] = Messages::get('timestamp_not_found');
                $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));
            }

            if (!empty($data['screenshot'])) {
                $statusUpdates[] = Messages::get('screenshot_captured');
                $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));
            } else {
                $statusUpdates[] = Messages::get('screenshot_failed');
                $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));
            }
        } else {
            $statusUpdates[] = Messages::get('page_not_accessible');
            $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));
        }

        $success = $this->botClient->sendMessage($chatId, Messages::get('test_notification_caption'));
        if ($success) {
            $statusUpdates[] = Messages::get('test_notification_sent');
        } else {
            $statusUpdates[] = Messages::get('test_notification_failed');
        }

        $this->botClient->editMessageText($chatId, $messageId, $resultHeader . implode("\n", $statusUpdates));

        return $resultHeader . implode("\n", $statusUpdates);
    }

    private function performFullTestAndReturnResult(): string
    {
        $statusUpdates = [];
        $resultHeader = Messages::get('test_results_header');
        
        $checkUrl = $this->settings->get('check_url');
        $data = $this->puppeteer->getCombinedData($checkUrl);

        if ($data) {
            $statusUpdates[] = Messages::get('page_accessible');
            $generatedOn = $this->parser->fetchGeneratedOn($data['content']);
            if ($generatedOn) {
                $statusUpdates[] = sprintf(Messages::get('timestamp_found'), $generatedOn);
                $convertedTime = $this->timeManager->convertTimezone($generatedOn);
                if ($convertedTime !== $generatedOn && false === strpos($convertedTime, 'conversion error')) {
                    $statusUpdates[] = sprintf(Messages::get('timestamp_found'), $convertedTime);
                } else {
                    $statusUpdates[] = sprintf(Messages::get('timezone_conversion_failed'), $convertedTime);
                }
            } else {
                $statusUpdates[] = Messages::get('timestamp_not_found');
            }
            if (!empty($data['screenshot'])) {
                $statusUpdates[] = Messages::get('screenshot_captured');
            } else {
                $statusUpdates[] = Messages::get('screenshot_failed');
            }
        } else {
            $statusUpdates[] = Messages::get('page_not_accessible');
        }

        $statusUpdates[] = Messages::get('initial_message_failed_fallback');
        
        return $resultHeader . implode("\n", $statusUpdates);
    }
}
