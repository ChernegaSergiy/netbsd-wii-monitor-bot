<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Config\SettingsManager;
use App\Constants\Messages;
use App\Telegram\BotClient;
use App\Utils\TimeManager;
use Psr\Log\LoggerInterface;

class Checker
{
    private SettingsManager $settings;
    private PuppeteerClient $puppeteer;
    private HtmlParser $parser;
    private TimeManager $time_manager;
    private BotClient $bot_client;
    private LoggerInterface $logger;

    public function __construct(
        SettingsManager $settings,
        PuppeteerClient $puppeteer,
        HtmlParser $parser,
        TimeManager $time_manager,
        BotClient $bot_client,
        LoggerInterface $logger
    ) {
        $this->settings = $settings;
        $this->puppeteer = $puppeteer;
        $this->parser = $parser;
        $this->time_manager = $time_manager;
        $this->bot_client = $bot_client;
        $this->logger = $logger;
    }

    public function processCheck(bool $force = false, int $attempt = 1) : bool
    {
        echo '[ ' . date('H:i:s') . " ] Check started…\n";

        $check_url = $this->settings->get('check_url');
        $cache_file = $this->settings->get('cache_file');

        $data = $this->puppeteer->getCombinedData($check_url);
        if (! $data) {
            $this->logger->error("Failed to get data from Puppeteer server.");
            return false;
        }

        if (! $this->parser->isScreenshotComplete($data['content'])) {
            $this->logger->warning("Screenshot is incomplete, scheduling retry in 1 minute…");
            sleep(60);
            return $this->processCheck($force, $attempt);
        }

        $generated_on = $this->parser->fetchGeneratedOn($data['content']);
        if (! $generated_on) {
            $this->logger->error("Generation timestamp not found in page content.");
            return false;
        }

        $last_gen = @file_get_contents($cache_file);

        if (! $force && ! $this->time_manager->isRecent($generated_on, 30) && $generated_on !== $last_gen) {
            $converted_time = $this->time_manager->convertTimezone($generated_on);
            $this->logger->info("Generation timestamp is not recent ({$converted_time}), retrying in 5 minutes…");
            return false;
        }

        if ($generated_on === $last_gen && ! $force) {
            if ($attempt < 3) {
                $this->logger->info("No new generation (attempt $attempt). Waiting 30s to see if NetBSD is just slow...");
                sleep(30);
                return $this->processCheck($force, $attempt + 1);
            }

            $this->logger->info("No new generation after 3 attempts.");
            return true;
        }

        file_put_contents($cache_file, $generated_on);

        $converted_time = $this->time_manager->convertTimezone($generated_on);
        $this->logger->info("Original timestamp: {$generated_on}");
        $this->logger->info("Converted timestamp: {$converted_time}");

        $image_path = 'screenshot.jpg';
        if (file_put_contents($image_path, base64_decode($data['screenshot']))) {
            $caption = sprintf(
                "New NetBSD Wii build:\nUTC: %s\nLocal: %s",
                $generated_on,
                $converted_time
            );

            $chat_id = $this->settings->get('chat_id');
            $success = $this->bot_client->sendPhoto($chat_id, $image_path, $caption);

            if ($success) {
                $this->logger->info("Screenshot sent with timestamp {$converted_time}");
            } else {
                $this->logger->error("Failed to send screenshot");
            }

            return $success;
        }

        $this->logger->error("Failed to save screenshot.");
        return false;
    }

    public function testCheck(string $chat_id) : string
    {
        $initial_message_text = Messages::get('test_starting');
        $sent_message = $this->bot_client->sendMessage($chat_id, $initial_message_text);

        $message_id = null;
        if ($sent_message && isset($sent_message['result']['message_id'])) {
            $message_id = $sent_message['result']['message_id'];
        } else {
            $this->logger->error("Failed to send initial test message to chat ID: {$chat_id}");
            return $this->performFullTestAndReturnResult();
        }

        $status_updates = [];
        $result_header = Messages::get('test_results_header');

        $check_url = $this->settings->get('check_url');
        $data = $this->puppeteer->getCombinedData($check_url);

        if ($data) {
            $status_updates[] = Messages::get('page_accessible');
            $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));

            $generated_on = $this->parser->fetchGeneratedOn($data['content']);
            if ($generated_on) {
                $status_updates[] = sprintf(Messages::get('timestamp_found'), $generated_on);
                $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));

                $converted_time = $this->time_manager->convertTimezone($generated_on);

                if ($converted_time !== $generated_on &&
                    false === strpos($converted_time, 'conversion error') &&
                    false === strpos($converted_time, 'conversion failed')) {
                    $status_updates[] = sprintf(Messages::get('timestamp_found'), $converted_time);
                } else {
                    $status_updates[] = sprintf(Messages::get('timezone_conversion_failed'), $converted_time);
                }
                $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));
            } else {
                $status_updates[] = Messages::get('timestamp_not_found');
                $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));
            }

            if (!empty($data['screenshot'])) {
                $status_updates[] = Messages::get('screenshot_captured');
                $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));

                $image_path = 'test_screenshot.jpg';
                if (file_put_contents($image_path, base64_decode($data['screenshot']))) {
                    $target_chat_id = $this->settings->get('chat_id');
                    $success = $this->bot_client->sendPhoto($target_chat_id, $image_path, Messages::get('test_notification_caption'));
                    if ($success) {
                        $status_updates[] = Messages::get('test_notification_sent');
                    } else {
                        $status_updates[] = Messages::get('test_notification_failed');
                    }
                    unlink($image_path);
                } else {
                    $status_updates[] = Messages::get('screenshot_failed');
                }
            } else {
                $status_updates[] = Messages::get('screenshot_failed');
                $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));

                $target_chat_id = $this->settings->get('chat_id');
                $success = $this->bot_client->sendMessage($target_chat_id, Messages::get('test_notification_caption'));
                if ($success) {
                    $status_updates[] = Messages::get('test_notification_sent');
                } else {
                    $status_updates[] = Messages::get('test_notification_failed');
                }
            }
        } else {
            $status_updates[] = Messages::get('page_not_accessible');
            $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));
        }

        $this->bot_client->editMessageText($chat_id, $message_id, $result_header . implode("\n", $status_updates));

        return $result_header . implode("\n", $status_updates);
    }

    private function performFullTestAndReturnResult() : string
    {
        $status_updates = [];
        $result_header = Messages::get('test_results_header');

        $check_url = $this->settings->get('check_url');
        $data = $this->puppeteer->getCombinedData($check_url);

        if ($data) {
            $status_updates[] = Messages::get('page_accessible');
            $generated_on = $this->parser->fetchGeneratedOn($data['content']);
            if ($generated_on) {
                $status_updates[] = sprintf(Messages::get('timestamp_found'), $generated_on);
                $converted_time = $this->time_manager->convertTimezone($generated_on);
                if ($converted_time !== $generated_on && false === strpos($converted_time, 'conversion error')) {
                    $status_updates[] = sprintf(Messages::get('timestamp_found'), $converted_time);
                } else {
                    $status_updates[] = sprintf(Messages::get('timezone_conversion_failed'), $converted_time);
                }
            } else {
                $status_updates[] = Messages::get('timestamp_not_found');
            }
            if (!empty($data['screenshot'])) {
                $status_updates[] = Messages::get('screenshot_captured');
            } else {
                $status_updates[] = Messages::get('screenshot_failed');
            }
        } else {
            $status_updates[] = Messages::get('page_not_accessible');
        }

        $status_updates[] = Messages::get('initial_message_failed_fallback');

        return $result_header . implode("\n", $status_updates);
    }
}
