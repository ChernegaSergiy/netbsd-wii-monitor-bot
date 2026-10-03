<?php

declare(strict_types=1);

namespace App\Constants;

class Messages
{
    public const ALL = [
        'welcome_admin' => 'Welcome to the bot admin panel! Please select an action:',
        'access_denied' => 'This is an administrative bot. You do not have access.',
        'no_access' => 'You do not have access to this bot.',
        'current_settings' => "📋 <b>Current Settings:</b>\n\n",
        'select_setting' => 'Select a setting to edit:',
        'enter_value' => "Enter a new value for %s:\n\nCurrent value: <code>%s</code>\nDescription: <i>%s</i>",
        'setting_updated' => "✅ Setting <b>%s</b> updated successfully.\n\nNew value: <code>%s</code>",
        'test_results_header' => "📋 <b>Test Results:</b>\n\n",
        'test_starting' => '⚙️ Starting test. Please wait…',
        'page_accessible' => '✅ Page is accessible.',
        'page_not_accessible' => '❌ Page is not accessible.',
        'timestamp_found' => '✅ Generated timestamp found: %s',
        'timezone_conversion_failed' => '❌ Timezone conversion failed. Result: %s',
        'timestamp_not_found' => '❌ Generated timestamp not found.',
        'screenshot_captured' => '✅ Screenshot captured.',
        'screenshot_failed' => '❌ Failed to capture screenshot.',
        'test_notification_sent' => '✅ Test notification sent successfully.',
        'test_notification_failed' => '❌ Failed to send test notification.',
        'check_completed' => '✅ Check completed successfully!',
        'check_failed' => '❌ Check failed.',
        'screenshot_settings' => "📸 <b>Screenshot Settings:</b>\n\nWidth: %spx\nHeight: %spx\nQuality: %s%%",
        'enter_new_value' => 'Enter new value for %s (current: %s):',
        'please_select_action' => 'Please select an action:',
        'initial_message_failed_fallback' => 'Failed to send initial message. Test completed without live updates.',
        'test_notification_caption' => 'Test Notification',
        'back_to_menu_text' => 'Back to main menu:',
        'btn_show_settings' => '📊 Show Settings',
        'btn_edit_setting' => '⚙️ Edit Setting',
        'btn_test' => '📱 Test',
        'btn_force_check' => '📡 Force Check',
        'btn_screenshot_settings_menu' => '📸 Screenshot Settings',
        'btn_set_width' => '🖼️ Set Width',
        'btn_set_height' => '🖼️ Set Height',
        'btn_set_quality' => '🎚️ Set Quality',
        'btn_back_to_menu' => '◀️ Back to Menu',
    ];

    public static function get(string $key) : string
    {
        return self::ALL[$key] ?? $key;
    }
}
