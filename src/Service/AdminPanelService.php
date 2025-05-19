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

    private ConsoleLogger $logger;

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
        $this->logger = new ConsoleLogger;
    }

    public function processAdminCommand(int $chatId, int $userId, string $text) : void
    {
        if (! $this->isAdmin($userId)) {
            $this->logger->warning("Access denied for user ID: {$userId}");
            $this->telegramClient->sendMessage($chatId, $this->messages['no_access']);

            return;
        }

        switch ($text) {
            case '/start':
                $this->logger->info("Admin panel accessed by user ID: {$userId}");
                $this->telegramClient->sendMessage(
                    $chatId,
                    $this->messages['welcome_admin'],
                    $this->keyboardService->createAdminKeyboard()
                );
                break;

            case $this->messages['show_settings']:
                $this->logger->info("Settings view requested by user ID: {$userId}");
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
                $this->logger->info("Settings edit mode accessed by user ID: {$userId}");
                $settings = $this->db->getAllSettings();
                $this->telegramClient->sendMessage(
                    $chatId,
                    $this->messages['select_setting'],
                    $this->keyboardService->createSettingsKeyboard($settings)
                );
                break;

            case $this->messages['test']:
                $this->logger->info("Test check initiated by user ID: {$userId}");
                $testResults = $this->testService->testCheck();
                $this->telegramClient->sendMessage($chatId, $testResults);
                break;

            case $this->messages['force_check']:
                $this->logger->info("Force check initiated by user ID: {$userId}");
                $result = $this->monitoringService->processCheck(true);
                $this->telegramClient->sendMessage(
                    $chatId,
                    $result ? $this->messages['check_completed'] : $this->messages['check_failed']
                );
                break;

            case $this->messages['screenshot_settings_menu']:
                $this->logger->info("Screenshot settings accessed by user ID: {$userId}");
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

            case $this->messages['set_width']:
            case $this->messages['set_height']:
            case $this->messages['set_quality']:
                $settingKey = match ($text) {
                    $this->messages['set_width'] => 'viewport_width',
                    $this->messages['set_height'] => 'viewport_height',
                    $this->messages['set_quality'] => 'image_quality',
                };

                $this->logger->info("Screenshot setting modification requested: {$settingKey}");
                $this->handleSettingModification($chatId, $userId, $settingKey);
                break;

            case $this->messages['back_to_menu']:
                $this->logger->info("User returned to main menu: {$userId}");
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

    private function handleSettingModification(int $chatId, int $userId, string $settingKey) : void
    {
        $sessionFile = "session_{$userId}.txt";
        file_put_contents($sessionFile, $settingKey);

        $currentValue = $this->db->getSetting($settingKey);
        $this->telegramClient->sendMessage(
            $chatId,
            sprintf($this->messages['enter_new_value'], $settingKey, $currentValue)
        );
    }

    private function handleSettingUpdate(int $chatId, int $userId, string $text) : void
    {
        $sessionFile = "session_{$userId}.txt";
        $settings = $this->db->getAllSettings();

        if (array_key_exists($text, $settings)) {
            $this->logger->info("Setting selected for editing: {$text}");
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

        if (file_exists($sessionFile)) {
            $settingKey = file_get_contents($sessionFile);
            $this->logger->info("Updating setting: {$settingKey} = {$text}");

            $this->db->updateSetting($settingKey, $text);
            unlink($sessionFile);

            $this->telegramClient->sendMessage(
                $chatId,
                sprintf($this->messages['setting_updated'], $settingKey, $text),
                $this->keyboardService->createAdminKeyboard()
            );

            return;
        }

        $this->logger->warning("Unknown command from user ID: {$userId}");
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
