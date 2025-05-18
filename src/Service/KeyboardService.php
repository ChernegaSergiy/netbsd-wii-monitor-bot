<?php

namespace WiiMonitor\Service;

class KeyboardService
{
    private array $messages;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function createAdminKeyboard() : string
    {
        return json_encode([
            'keyboard' => [
                [['text' => $this->messages['show_settings']]],
                [['text' => $this->messages['edit_setting']]],
                [['text' => $this->messages['test']], ['text' => $this->messages['force_check']]],
                [['text' => $this->messages['screenshot_settings_menu']]],
            ],
            'resize_keyboard' => true,
        ]);
    }

    public function createScreenshotSettingsKeyboard() : string
    {
        return json_encode([
            'keyboard' => [
                [['text' => $this->messages['set_width']], ['text' => $this->messages['set_height']]],
                [['text' => $this->messages['set_quality']]],
                [['text' => $this->messages['back_to_menu']]],
            ],
            'resize_keyboard' => true,
        ]);
    }

    public function createSettingsKeyboard(array $settings) : string
    {
        $keyboard = [[]];
        $i = 0;

        foreach ($settings as $key => $data) {
            if (0 == $i % 2 && $i > 0) {
                $keyboard[] = [];
            }

            $keyboard[count($keyboard) - 1][] = ['text' => $key];
            $i++;
        }

        $keyboard[] = [['text' => $this->messages['back_to_menu']]];

        return json_encode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
        ]);
    }
}
