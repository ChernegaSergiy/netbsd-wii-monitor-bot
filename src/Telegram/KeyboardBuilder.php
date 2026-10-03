<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Constants\Messages;

class KeyboardBuilder
{
    public static function createAdminKeyboard() : array
    {
        return [
            'keyboard' => [
                [['text' => Messages::get('btn_show_settings')]],
                [['text' => Messages::get('btn_edit_setting')]],
                [
                    ['text' => Messages::get('btn_test')],
                    ['text' => Messages::get('btn_force_check')],
                ],
                [['text' => Messages::get('btn_screenshot_settings_menu')]],
            ],
            'resize_keyboard' => true,
        ];
    }

    public static function createScreenshotSettingsKeyboard() : array
    {
        return [
            'keyboard' => [
                [
                    ['text' => Messages::get('btn_set_width')],
                    ['text' => Messages::get('btn_set_height')],
                ],
                [['text' => Messages::get('btn_set_quality')]],
                [['text' => Messages::get('btn_back_to_menu')]],
            ],
            'resize_keyboard' => true,
        ];
    }

    public static function createSettingsKeyboard(array $settings) : array
    {
        $keyboard = [[]];
        $i = 0;

        foreach ($settings as $key => $data) {
            if (0 === $i % 2 && $i > 0) {
                $keyboard[] = [];
            }

            $keyboard[count($keyboard) - 1][] = ['text' => $key];
            $i++;
        }

        $keyboard[] = [['text' => Messages::get('btn_back_to_menu')]];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
        ];
    }
}
