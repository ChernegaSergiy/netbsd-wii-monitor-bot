<?php

namespace WiiMonitor\Service;

use WiiMonitor\Interfaces\DatabaseInterface;
use WiiMonitor\Interfaces\TelegramClientInterface;

class AdminPanelService
{
    private DatabaseInterface $db;

    private TelegramClientInterface $telegramClient;

    private KeyboardService $keyboardService;

    private TestService $testService;

    private MonitoringService $monitoringService;

    private array $messages;

    private array $adminIds;

    public function __construct(
        DatabaseInterface $db,
        TelegramClientInterface $telegramClient,
        KeyboardService $keyboardService,
        TestService $testService,
        MonitoringService $monitoringService,
        array $messages,
        array $adminIds
    ) {
        $this->db = $db;
        $this->telegramClient = $telegramClient;
        $this->keyboardService = $keyboardService;
        $this->testService = $testService;
        $this->monitoringService = $monitoringService;
        $this->messages = $messages;
        $this->adminIds = $adminIds;
    }

    public function processAdminCommand(int $chatId, int $userId, string $text) : void
    {
        if (! $this->isAdmin($userId)) {
            $this->telegramClient->sendMessage($chatId, $this->messages['no_access']);

            return;
        }

        switch ($text) {
            case '/start':
                $this->telegramClient->sendMessage(
                    $chatId,
                    $this->messages['welcome_admin'],
                    $this->keyboardService->createAdminKeyboard()
                );
                break;

            case $this->messages['show_settings']:
                $settings = $this->db->getAllSettings();
                $responseText = $this->messages['current_settings'];

                foreach ($settings as $key => $data) {
                    $responseText .= sprintf(
                        "<b>%s</b>: %s\n<i>%s</i>\n\n",
                        $key,
                        $data['value'],
                        $data['description']
                    );
                }

                $this->telegramClient->sendMessage($chatId, $responseText);
                break;

            case $this->messages['edit_setting']:
                $settings = $this->db->getAllSettings();
                $this->telegramClient->sendMessage(
                    $chatId,
                    $this->messages['select_setting'],
                    $this->keyboardService->createSettingsKeyboard($settings)
                );
                break;

            case $this->messages['test']:
                $testResults = $this->testService->testCheck();
                $this->telegramClient->sendMessage($chatId, $testResults);
                break;

            case $this->messages['force_check']:
                $result = $this->monitoringService->processCheck(true);
                $this->telegramClient->sendMessage(
                    $chatId,
                    $result ? $this->messages['check_completed'] : $this->messages['check_failed']
                );
                break;

            case $this->messages['screenshot_settings_menu']:
                $currentWidth = $this->db->getSetting('viewport_width');
                $currentHeight = $this->db->getSetting('viewport_height');
                $currentQuality = $this->db->getSetting('image_quality');

                $message = sprintf(
                    $this->messages['screenshot_settings'],
                    $currentWidth,
                    $currentHeight,
                    $currentQuality
                );

                $this->telegramClient->sendMessage(
                    $chatId,
                    $message,
                    $this->keyboardService->createScreenshotSettingsKeyboard()
                );
                break;

            case $this->messages['back_to_menu']:
                $this->telegramClient->sendMessage(
                    $chatId,
                    $this->messages['back_to_menu'],
                    $this->keyboardService->createAdminKeyboard()
                );
                break;

            default:
                $this->handleSettingUpdate($chatId, $userId, $text);
        }
    }

    private function handleSettingUpdate(int $chatId, int $userId, string $text) : void
    {
        $sessionFile = "session_{$userId}.txt";
        $settings = $this->db->getAllSettings();

        // Check if selecting a setting to edit
        if (array_key_exists($text, $settings)) {
            file_put_contents($sessionFile, $text);
            $responseText = sprintf(
                $this->messages['enter_value'],
                $text,
                $settings[$text]['value'],
                $settings[$text]['description']
            );
            $this->telegramClient->sendMessage($chatId, $responseText);

            return;
        }

        // Check if updating a setting
        if (file_exists($sessionFile)) {
            $settingKey = file_get_contents($sessionFile);
            $this->db->updateSetting($settingKey, $text);
            unlink($sessionFile);

            $this->telegramClient->sendMessage(
                $chatId,
                sprintf($this->messages['setting_updated'], $settingKey, $text),
                $this->keyboardService->createAdminKeyboard()
            );

            return;
        }

        // Default response
        $this->telegramClient->sendMessage(
            $chatId,
            $this->messages['please_select_action'],
            $this->keyboardService->createAdminKeyboard()
        );
    }

    private function isAdmin(int $userId) : bool
    {
        return in_array($userId, $this->adminIds);
    }
}
