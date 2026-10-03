<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config\SettingsManager;
use App\Constants\Messages;
use App\Monitor\Checker;

class CommandRouter
{
    private BotClient $botClient;
    private SettingsManager $settings;
    private Checker $checker;
    private array $adminIds;

    public function __construct(
        BotClient $botClient,
        SettingsManager $settings,
        Checker $checker,
        array $adminIds
    ) {
        $this->botClient = $botClient;
        $this->settings = $settings;
        $this->checker = $checker;
        $this->adminIds = $adminIds;
    }

    public function processUpdate(array $update): void
    {
        if (!isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';

        if ('/start' === $text) {
            if ($this->isAdmin($userId)) {
                $this->botClient->sendMessage(
                    (string) $chatId,
                    Messages::get('welcome_admin'),
                    KeyboardBuilder::createAdminKeyboard()
                );
            } else {
                $this->botClient->sendMessage((string) $chatId, Messages::get('access_denied'));
            }
            return;
        }

        if (!$this->isAdmin($userId)) {
            $this->botClient->sendMessage((string) $chatId, Messages::get('no_access'));
            return;
        }

        $this->handleCommand((string) $chatId, $userId, $text);
    }

    private function isAdmin(int $userId): bool
    {
        return in_array($userId, $this->adminIds, true);
    }

    private function handleCommand(string $chatId, int $userId, string $text): void
    {
        switch ($text) {
            case Messages::get('btn_show_settings'):
                $allSettings = $this->settings->getAll();
                $responseText = Messages::get('current_settings');
                foreach ($allSettings as $key => $data) {
                    $responseText .= sprintf("<b>%s</b>: %s\n<i>%s</i>\n\n", $key, $data['value'], $data['description']);
                }
                $this->botClient->sendMessage($chatId, $responseText);
                break;

            case Messages::get('btn_edit_setting'):
                $allSettings = $this->settings->getAll();
                $this->botClient->sendMessage(
                    $chatId,
                    Messages::get('select_setting'),
                    KeyboardBuilder::createSettingsKeyboard($allSettings)
                );
                break;

            case Messages::get('btn_test'):
                $this->checker->testCheck($chatId);
                break;

            case Messages::get('btn_force_check'):
                $result = $this->checker->processCheck(true);
                $this->botClient->sendMessage(
                    $chatId,
                    $result ? Messages::get('check_completed') : Messages::get('check_failed')
                );
                break;

            case Messages::get('btn_screenshot_settings_menu'):
                $width = $this->settings->get('viewport_width');
                $height = $this->settings->get('viewport_height');
                $quality = $this->settings->get('image_quality');
                $message = sprintf(Messages::get('screenshot_settings'), $width, $height, $quality);
                $this->botClient->sendMessage(
                    $chatId,
                    $message,
                    KeyboardBuilder::createScreenshotSettingsKeyboard()
                );
                break;

            case Messages::get('btn_set_width'):
            case Messages::get('btn_set_height'):
            case Messages::get('btn_set_quality'):
                $settingKey = '';
                if ($text === Messages::get('btn_set_width')) {
                    $settingKey = 'viewport_width';
                } elseif ($text === Messages::get('btn_set_height')) {
                    $settingKey = 'viewport_height';
                } elseif ($text === Messages::get('btn_set_quality')) {
                    $settingKey = 'image_quality';
                }

                file_put_contents($this->getSessionFile($userId), $settingKey);
                $allSettings = $this->settings->getAll();
                $currentValue = $allSettings[$settingKey]['value'] ?? '';
                $description = $allSettings[$settingKey]['description'] ?? '';
                $this->botClient->sendMessage(
                    $chatId,
                    sprintf(Messages::get('enter_value'), $settingKey, $currentValue, $description)
                );
                break;

            case Messages::get('btn_back_to_menu'):
                $this->botClient->sendMessage(
                    $chatId,
                    Messages::get('back_to_menu_text'),
                    KeyboardBuilder::createAdminKeyboard()
                );
                break;

            default:
                $this->handleDefaultText($chatId, $userId, $text);
        }
    }

    private function handleDefaultText(string $chatId, int $userId, string $text): void
    {
        $allSettings = $this->settings->getAll();
        
        // Check if selecting a setting to edit
        if (array_key_exists($text, $allSettings)) {
            file_put_contents($this->getSessionFile($userId), $text);
            $responseText = sprintf(
                Messages::get('enter_value'),
                $text,
                $allSettings[$text]['value'],
                $allSettings[$text]['description']
            );
            $this->botClient->sendMessage($chatId, $responseText);
            return;
        }

        // Check if updating a setting
        $sessionFile = $this->getSessionFile($userId);
        if (file_exists($sessionFile)) {
            $settingKey = file_get_contents($sessionFile);
            $this->settings->update($settingKey, $text);
            unlink($sessionFile);

            $this->botClient->sendMessage(
                $chatId,
                sprintf(Messages::get('setting_updated'), $settingKey, $text),
                KeyboardBuilder::createAdminKeyboard()
            );
            return;
        }

        // Default response
        $this->botClient->sendMessage(
            $chatId,
            Messages::get('please_select_action'),
            KeyboardBuilder::createAdminKeyboard()
        );
    }

    private function getSessionFile(int $userId): string
    {
        return "session_{$userId}.txt";
    }
}
