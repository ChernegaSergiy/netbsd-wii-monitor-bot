<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config\SettingsManager;
use App\Constants\Messages;
use App\Monitor\Checker;

class CommandRouter
{
    private BotClient $bot_client;
    private SettingsManager $settings;
    private Checker $checker;
    private array $admin_ids;

    public function __construct(
        BotClient $bot_client,
        SettingsManager $settings,
        Checker $checker,
        array $admin_ids
    ) {
        $this->bot_client = $bot_client;
        $this->settings = $settings;
        $this->checker = $checker;
        $this->admin_ids = $admin_ids;
    }

    public function processUpdate(array $update) : void
    {
        if (! isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = $message['text'] ?? '';

        if ('/start' === $text) {
            if ($this->isAdmin($user_id)) {
                $this->bot_client->sendMessage(
                    (string) $chat_id,
                    Messages::get('welcome_admin'),
                    KeyboardBuilder::createAdminKeyboard()
                );
            } else {
                $this->bot_client->sendMessage((string) $chat_id, Messages::get('access_denied'));
            }
            return;
        }

        if (! $this->isAdmin($user_id)) {
            $this->bot_client->sendMessage((string) $chat_id, Messages::get('no_access'));
            return;
        }

        $this->handleCommand((string) $chat_id, $user_id, $text);
    }

    private function isAdmin(int $user_id) : bool
    {
        return in_array($user_id, $this->admin_ids, true);
    }

    private function handleCommand(string $chat_id, int $user_id, string $text) : void
    {
        switch ($text) {
            case Messages::get('btn_show_settings'):
                $all_settings = $this->settings->getAll();
                $response_text = Messages::get('current_settings');
                foreach ($all_settings as $key => $data) {
                    $response_text .= sprintf("<b>%s</b>: %s\n<i>%s</i>\n\n", $key, $data['value'], $data['description']);
                }
                $this->bot_client->sendMessage($chat_id, $response_text);
                break;

            case Messages::get('btn_edit_setting'):
                $all_settings = $this->settings->getAll();
                $this->bot_client->sendMessage(
                    $chat_id,
                    Messages::get('select_setting'),
                    KeyboardBuilder::createSettingsKeyboard($all_settings)
                );
                break;

            case Messages::get('btn_test'):
                $this->checker->testCheck($chat_id);
                break;

            case Messages::get('btn_force_check'):
                $result = $this->checker->processCheck(true);
                $this->bot_client->sendMessage(
                    $chat_id,
                    $result ? Messages::get('check_completed') : Messages::get('check_failed')
                );
                break;

            case Messages::get('btn_screenshot_settings_menu'):
                $width = $this->settings->get('viewport_width');
                $height = $this->settings->get('viewport_height');
                $quality = $this->settings->get('image_quality');
                $message = sprintf(Messages::get('screenshot_settings'), $width, $height, $quality);
                $this->bot_client->sendMessage(
                    $chat_id,
                    $message,
                    KeyboardBuilder::createScreenshotSettingsKeyboard()
                );
                break;

            case Messages::get('btn_set_width'):
            case Messages::get('btn_set_height'):
            case Messages::get('btn_set_quality'):
                $setting_key = '';
                if ($text === Messages::get('btn_set_width')) {
                    $setting_key = 'viewport_width';
                } elseif ($text === Messages::get('btn_set_height')) {
                    $setting_key = 'viewport_height';
                } elseif ($text === Messages::get('btn_set_quality')) {
                    $setting_key = 'image_quality';
                }

                file_put_contents($this->getSessionFile($user_id), $setting_key);
                $all_settings = $this->settings->getAll();
                $current_value = $all_settings[$setting_key]['value'] ?? '';
                $description = $all_settings[$setting_key]['description'] ?? '';
                $this->bot_client->sendMessage(
                    $chat_id,
                    sprintf(Messages::get('enter_value'), $setting_key, $current_value, $description)
                );
                break;

            case Messages::get('btn_back_to_menu'):
                $this->bot_client->sendMessage(
                    $chat_id,
                    Messages::get('back_to_menu_text'),
                    KeyboardBuilder::createAdminKeyboard()
                );
                break;

            default:
                $this->handleDefaultText($chat_id, $user_id, $text);
        }
    }

    private function handleDefaultText(string $chat_id, int $user_id, string $text) : void
    {
        $all_settings = $this->settings->getAll();

        // Check if selecting a setting to edit
        if (array_key_exists($text, $all_settings)) {
            file_put_contents($this->getSessionFile($user_id), $text);
            $response_text = sprintf(
                Messages::get('enter_value'),
                $text,
                $all_settings[$text]['value'],
                $all_settings[$text]['description']
            );
            $this->bot_client->sendMessage($chat_id, $response_text);
            return;
        }

        // Check if updating a setting
        $session_file = $this->getSessionFile($user_id);
        if (file_exists($session_file)) {
            $setting_key = file_get_contents($session_file);
            $this->settings->update($setting_key, $text);
            unlink($session_file);

            $this->bot_client->sendMessage(
                $chat_id,
                sprintf(Messages::get('setting_updated'), $setting_key, $text),
                KeyboardBuilder::createAdminKeyboard()
            );
            return;
        }

        // Default response
        $this->bot_client->sendMessage(
            $chat_id,
            Messages::get('please_select_action'),
            KeyboardBuilder::createAdminKeyboard()
        );
    }

    private function getSessionFile(int $user_id) : string
    {
        return "session_{$user_id}.txt";
    }
}
