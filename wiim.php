<?php

/**
 * Telegram Website Monitoring Bot
 *
 * This bot monitors a website for changes and sends notifications via Telegram.
 * It checks for timestamp changes and captures screenshots of the monitored site.
 */

require_once 'vendor/autoload.php'; // Load libraries via Composer
use Dotenv\Dotenv;

// Error reporting for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration
$bot_token = getenv('BOT_TOKEN');
$admin_ids = explode(',', getenv('ADMIN_IDS'));
$db_file = getenv('DB_FILE') ?: 'bot_config.db';

// Check if all required environment variables are set
if (! $bot_token || empty($admin_ids)) {
    exit("Error! Missing required configuration variables.\n");
}

$messages = [
    'welcome_admin' => 'Welcome to the bot admin panel! Please select an action:',
    'access_denied' => 'This is an administrative bot. You do not have access.',
    'no_access' => 'You do not have access to this bot.',
    'current_settings' => "📋 <b>Current Settings:</b>\n\n",
    'select_setting' => 'Select a setting to edit:',
    'enter_value' => "Enter a new value for %s:\n\nCurrent value: <code>%s</code>\nDescription: <i>%s</i>",
    'setting_updated' => "✅ Setting <b>%s</b> updated successfully.\n\nNew value: <code>%s</code>",
    'back_to_menu' => 'Back to main menu:',
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
    'screenshot_settings' => "📸 <b>Screenshot Settings</b>\n\nWidth: %spx\nHeight: %spx\nQuality: %s%%",
    'enter_new_value' => 'Enter new value for %s (current: %s):',
    'please_select_action' => 'Please select an action:',
    'show_settings' => '📊 Show Settings',
    'edit_setting' => '⚙️ Edit Setting',
    'test' => '📱 Test',
    'force_check' => '📡 Force Check',
    'screenshot_settings_menu' => '📸 Screenshot Settings',
    'set_width' => '🖼️ Set Width',
    'set_height' => '🖼️ Set Height',
    'set_quality' => '🎚️ Set Quality',
    'back_to_menu' => '◀️ Back to Menu',
    'back_to_menu' => '◀️ Back to Menu',
    'initial_message_failed_fallback' => 'Failed to send initial message. Test completed without live updates.',
    'test_notification_caption' => 'Test Notification',
];

/**
 * Initialize database with default settings
 *
 * @param  string  $db_file
 * @return SQLite3
 */
function initDatabase($db_file)
{
    $db = new SQLite3($db_file);

    // Create settings table if doesn't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            description TEXT
        )
    ');

    // Default settings
    $default_settings = [
        'check_url' => ['https://blog.infected.systems/status', 'URL to check'],
        'chat_id' => ['-1234567890', 'Chat ID for notifications'],
        'cache_file' => ['last_gen.txt', 'Cache file'],
        'source_timezone' => ['UTC', 'Source timezone'],
        'target_timezone' => ['Europe/Kiev', 'Target timezone'],
        'check_interval' => ['1800', 'Check interval in seconds'],
        'viewport_width' => ['1280', 'Screenshot width in pixels'],
        'viewport_height' => ['720', 'Screenshot height in pixels'],
        'image_quality' => ['80', 'Screenshot quality (1-100)'],
        'puppeteer_server' => ['http://localhost:3000', 'Puppeteer server URL'],
    ];

    // Initialize settings
    foreach ($default_settings as $key => $data) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value, description) VALUES (:key, :value, :description)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $data[0], SQLITE3_TEXT);
        $stmt->bindValue(':description', $data[1], SQLITE3_TEXT);
        $stmt->execute();
    }

    return $db;
}

/**
 * Get all settings from database
 *
 * @param  SQLite3  $db
 * @return array
 */
function getAllSettings($db)
{
    $result = $db->query('SELECT key, value, description FROM settings');
    $settings = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $settings[$row['key']] = [
            'value' => $row['value'],
            'description' => $row['description'],
        ];
    }

    return $settings;
}

/**
 * Get a specific setting value
 *
 * @param  SQLite3  $db
 * @param  string  $key
 * @return string|null
 */
function getSetting($db, $key)
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        return $row['value'];
    }

    return null;
}

/**
 * Update a setting value
 *
 * @param  SQLite3  $db
 * @param  string  $key
 * @param  string  $value
 * @return bool
 */
function updateSetting($db, $key, $value)
{
    $stmt = $db->prepare('UPDATE settings SET value = :value WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);

    return (bool) $stmt->execute();
}

/**
 * Send message via Telegram API
 *
 * @param  string  $bot_token
 * @param  int  $chat_id
 * @param  string  $text
 * @param  array|null  $keyboard
 * @return array|false
 */
function sendTelegramMessage($bot_token, $chat_id, $text, $keyboard = null)
{
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

    $post_data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    if (null !== $keyboard) {
        $post_data['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Telegram sendMessage error: ' . $error);

        return false;
    }

    return json_decode($response, true);
}

/**
 * Edit message via Telegram API
 *
 * @param  string  $bot_token
 * @param  int  $chat_id
 * @param  int  $message_id
 * @param  string  $text
 * @param  array|null  $keyboard
 * @return array|false
 */
function editTelegramMessage($bot_token, $chat_id, $message_id, $text, $keyboard = null)
{
    $url = "https://api.telegram.org/bot{$bot_token}/editMessageText";

    $post_data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    if (null !== $keyboard) {
        $post_data['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Telegram editMessageText error: ' . $error);

        return false;
    }

    return json_decode($response, true);
}

/**
 * Check if user is admin
 *
 * @param  int  $user_id
 * @param  array  $admin_ids
 * @return bool
 */
function isAdmin($user_id, $admin_ids)
{
    return in_array($user_id, $admin_ids);
}

/**
 * Create main admin keyboard
 *
 * @return string
 */
function createAdminKeyboard()
{
    return json_encode([
        'keyboard' => [
            [['text' => $GLOBALS['messages']['show_settings']]],
            [['text' => $GLOBALS['messages']['edit_setting']]],
            [['text' => $GLOBALS['messages']['test']], ['text' => $GLOBALS['messages']['force_check']]],
            [['text' => $GLOBALS['messages']['screenshot_settings_menu']]],
        ],
        'resize_keyboard' => true,
    ]);
}

/**
 * Create screenshot settings keyboard
 *
 * @return string
 */
function createScreenshotSettingsKeyboard()
{
    return json_encode([
        'keyboard' => [
            [['text' => $GLOBALS['messages']['set_width']], ['text' => $GLOBALS['messages']['set_height']]],
            [['text' => $GLOBALS['messages']['set_quality']]],
            [['text' => $GLOBALS['messages']['back_to_menu']]],
        ],
        'resize_keyboard' => true,
    ]);
}

/**
 * Create settings keyboard
 *
 * @param  array  $settings
 * @return string
 */
function createSettingsKeyboard($settings)
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

    $keyboard[] = [['text' => $GLOBALS['messages']['back_to_menu']]];

    return json_encode([
        'keyboard' => $keyboard,
        'resize_keyboard' => true,
    ]);
}

/**
 * Calculate wait time until next check
 *
 * @param  bool  $initial_check
 * @param  SQLite3  $db
 * @return int
 */
function waitUntilNextHalfHour($initial_check, $db)
{
    $now = time();
    $next = strtotime(date('Y-m-d H:00')) + (date('i') < 30 ? 1800 : 3600);

    if ($initial_check) {
        $cache_file = getSetting($db, 'cache_file');
        if (! file_exists($cache_file)) {
            return 0;
        }
    }

    return $next - $now;
}

/**
 * Get combined data (page content and screenshot) from Puppeteer server
 *
 * @param  string  $url
 * @param  SQLite3  $db
 * @param  int  $max_retries
 * @return array|false
 */
function getCombinedData($url, $db, $max_retries = 3)
{
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $puppeteer_server = getSetting($db, 'puppeteer_server');
        $viewport_width = getSetting($db, 'viewport_width');
        $viewport_height = getSetting($db, 'viewport_height');
        $image_quality = getSetting($db, 'image_quality');

        $data = [
            'url' => $url,
            'viewport' => [
                'width' => (int) $viewport_width,
                'height' => (int) $viewport_height,
                'quality' => (int) $image_quality,
            ],
        ];

        $ch = curl_init($puppeteer_server . '/combined');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (! $error && 200 === $http_code) {
            $result = json_decode($response, true);
            if ($result && isset($result['content']) && isset($result['screenshot'])) {
                return $result;
            }
        }

        error_log("Combined request attempt $attempt failed: " . ($error ?: "HTTP $http_code"));

        if ($attempt < $max_retries) {
            sleep(10); // Wait 10 seconds before retrying
        }
    }

    return false;
}

/**
 * Fetch generation timestamp from the page content
 *
 * @param  string  $content
 * @return string|false
 */
function fetchGeneratedOn($content)
{
    $patterns = [
        '/Generated on:\s+([^\n<]+)/',
        '/Generated:\s+([^\n<]+)/',
        '/timestamp[^:]*:\s+([^\n<]+)/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content, $match)) {
            $timestamp = trim($match[1]);
            error_log("Found timestamp: $timestamp");

            return $timestamp;
        }
    }

    error_log('No timestamp found in page content');

    return false;
}

/**
 * Convert timestamp between timezones
 *
 * @param  string  $timestamp_str
 * @param  string  $from_tz
 * @param  string  $to_tz
 * @return string
 */
function convertTimezone($timestamp_str, $from_tz, $to_tz)
{
    try {
        if (preg_match('/^\w{3} \w{3} \d{1,2} \d{2}:\d{2}:\d{2} UTC \d{4}$/', $timestamp_str)) {
            $datetime = DateTime::createFromFormat('D M d H:i:s T Y', $timestamp_str);
            if (! $datetime) {
                $timestamp = strtotime($timestamp_str);
                if (false !== $timestamp) {
                    $datetime = new DateTime;
                    $datetime->setTimestamp($timestamp);
                    $datetime->setTimezone(new DateTimeZone($from_tz));
                }
            }
        } else {
            $timestamp = strtotime($timestamp_str);
            if (false !== $timestamp) {
                $datetime = new DateTime;
                $datetime->setTimestamp($timestamp);
                $datetime->setTimezone(new DateTimeZone($from_tz));
            } else {
                $datetime = false;
            }
        }

        if (! $datetime) {
            error_log("Failed to parse timestamp: $timestamp_str");

            return $timestamp_str . ' (conversion failed)';
        }

        $datetime->setTimezone(new DateTimeZone($to_tz));

        return $datetime->format('Y-m-d H:i:s') . " ({$to_tz})";
    } catch (Exception $e) {
        error_log('Timezone conversion error: ' . $e->getMessage());

        return $timestamp_str . ' (conversion error)';
    }
}

/**
 * Check if timestamp is recent
 *
 * @param  string  $timestamp_str
 * @param  int  $minutes
 * @param  SQLite3  $db
 * @return bool
 */
function isRecent($timestamp_str, $minutes, $db)
{
    $source_timezone = getSetting($db, 'source_timezone');
    try {
        $dt = new DateTime($timestamp_str, new DateTimeZone($source_timezone));
        $now = new DateTime('now', new DateTimeZone($source_timezone));
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return $diff <= ($minutes * 60);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Take screenshot of the target URL
 *
 * @param  string  $target_url
 * @param  SQLite3  $db
 * @return string|false
 */
function takeScreenshot($target_url, $db)
{
    $puppeteer_server = getSetting($db, 'puppeteer_server');
    $viewport_width = getSetting($db, 'viewport_width');
    $viewport_height = getSetting($db, 'viewport_height');
    $image_quality = getSetting($db, 'image_quality');

    $data = [
        'url' => $target_url,
        'viewport' => [
            'width' => (int) $viewport_width,
            'height' => (int) $viewport_height,
            'quality' => (int) $image_quality,
        ],
    ];

    $ch = curl_init($puppeteer_server . '/screenshot');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || 200 !== $http_code) {
        error_log('Screenshot error: ' . ($error ?: "HTTP $http_code"));

        return false;
    }

    return $response;
}

/**
 * Send screenshot to Telegram
 *
 * @param  string  $image_path
 * @param  string  $caption
 * @param  SQLite3  $db
 * @return bool
 */
function sendScreenshot($image_path, $caption, $db)
{
    global $bot_token;
    $chat_id = getSetting($db, 'chat_id');

    $send_url = "https://api.telegram.org/bot{$bot_token}/sendPhoto";
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $send_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat_id,
            'photo' => new CURLFile($image_path),
            'caption' => $caption,
        ],
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (false === $response) {
        error_log('Telegram API request failed: curl error');

        return false;
    }

    $result = json_decode($response, true);
    if (! $result || ! isset($result['ok']) || true !== $result['ok']) {
        error_log('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));

        return false;
    }

    return true;
}

/**
 * Check if screenshot contains all required information
 *
 * @param  string  $content
 * @return bool
 */
function isScreenshotComplete($content)
{
    // Look for the '=== top ===' section start
    $top_start = strpos($content, '=== top ===');

    if (false === $top_start) {
        // '=== top ===' section not found, screenshot is incomplete.
        // This likely means the page content wasn't fully rendered or captured.
        return false;
    }

    // Extract the content starting from '=== top ==='
    $top_section_content = substr($content, $top_start);

    // Now, check if 'load averages:' is present WITHIN the extracted 'top' section.
    // This ensures that the 'load averages:' string is part of the 'top' output,
    // and not just some other part of the page content that might appear earlier.
    if (false === strpos($top_section_content, 'load averages:')) {
        // 'load averages:' not found within the 'top' section, indicating incomplete data.
        return false;
    }

    // If both conditions are met, the relevant part of the screenshot is considered complete.
    return true;
}

/**
 * Process check logic
 *
 * @param  SQLite3  $db
 * @param  bool  $force
 * @return bool
 */
function processCheck($db, $force = false)
{
    echo '[ ' . date('H:i:s') . " ] Check started…\n";

    $check_url = getSetting($db, 'check_url');
    $cache_file = getSetting($db, 'cache_file');
    $source_timezone = getSetting($db, 'source_timezone');
    $target_timezone = getSetting($db, 'target_timezone');

    // Get combined data from Puppeteer server
    $data = getCombinedData($check_url, $db);
    if (! $data) {
        echo "Failed to get data from Puppeteer server.\n";

        return false;
    }

    // Check if screenshot is complete
    if (! isScreenshotComplete($data['content'])) {
        echo "Screenshot is incomplete, scheduling retry in 1 minute…\n";
        sleep(60); // Wait 1 minute before retrying

        return processCheck($db, $force); // Recursive retry
    }

    // Extract timestamp from content
    $generated_on = fetchGeneratedOn($data['content']);
    if (! $generated_on) {
        echo "Generation timestamp not found in page content.\n";

        return false;
    }

    $last_gen = @file_get_contents($cache_file);

    // Check if timestamp is recent and different from the cached one
    if (! $force && ! isRecent($generated_on, 30, $db) && $generated_on !== $last_gen) {
        $converted_time = convertTimezone($generated_on, $source_timezone, $target_timezone);
        echo "Generation timestamp is not recent ({$converted_time}), retrying in 5 minutes…\n";

        return false;
    }

    if ($generated_on === $last_gen && ! $force) {
        echo "No new generation.\n";

        return true;
    }

    // Save new timestamp
    file_put_contents($cache_file, $generated_on);

    $converted_time = convertTimezone($generated_on, $source_timezone, $target_timezone);
    echo "Original timestamp: {$generated_on}\n";
    echo "Converted timestamp: {$converted_time}\n";

    // Save and send screenshot
    $image_path = 'screenshot.jpg';
    if (file_put_contents($image_path, base64_decode($data['screenshot']))) {
        $caption = sprintf("New NetBSD Wii build:\nUTC: %s\nLocal: %s",
            $generated_on,
            $converted_time
        );

        $success = sendScreenshot($image_path, $caption, $db);
        if ($success) {
            echo "Screenshot sent with timestamp {$converted_time}\n";
        } else {
            echo "Failed to send screenshot\n";
        }

        return $success;
    }

    echo "Failed to save screenshot.\n";

    return false;
}

/**
 * Test check functionality
 *
 * @param  SQLite3  $db
 * @param  int  $chat_id
 * @return string
 */
function testCheck($db, $chat_id)
{
    global $bot_token, $messages;

    // Send initial "starting" message
    $initial_message_text = $messages['test_starting'];
    $sent_message = sendTelegramMessage($bot_token, $chat_id, $initial_message_text);

    $message_id = null;
    if ($sent_message && isset($sent_message['result']['message_id'])) {
        $message_id = $sent_message['result']['message_id'];
    } else {
        error_log("Failed to send initial test message to chat ID: {$chat_id}");

        return _performFullTestAndReturnResult($db);
    }

    // Initialize array to store status updates
    $status_updates = [];
    $result_header = $messages['test_results_header'];

    // Test page access and get data
    $check_url = getSetting($db, 'check_url');
    $data = getCombinedData($check_url, $db);

    if ($data) {
        $status_updates[] = $messages['page_accessible'];
        editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));

        // Extract timestamp
        $generated_on = fetchGeneratedOn($data['content']);
        if ($generated_on) {
            $status_updates[] = sprintf($messages['timestamp_found'], $generated_on);
            editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));

            // Convert timezones
            $source_timezone = getSetting($db, 'source_timezone');
            $target_timezone = getSetting($db, 'target_timezone');
            $converted_time = convertTimezone($generated_on, $source_timezone, $target_timezone);

            if ($converted_time !== $generated_on &&
                false === strpos($converted_time, 'conversion error') &&
                false === strpos($converted_time, 'conversion failed')) {
                $status_updates[] = sprintf($messages['timestamp_found'], $converted_time);
            } else {
                $status_updates[] = sprintf($messages['timezone_conversion_failed'], $converted_time);
            }
            editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));
        } else {
            $status_updates[] = $messages['timestamp_not_found'];
            editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));
        }

        // Test screenshot
        $status_updates[] = $messages['screenshot_captured'];
        editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));

        $image_path = 'test_screenshot.jpg';
        if (file_put_contents($image_path, base64_decode($data['screenshot']))) {
            // Test notification
            $test_caption = $messages['test_notification_caption'];
            $success = sendScreenshot($image_path, $test_caption, $db);
            if ($success) {
                $status_updates[] = $messages['test_notification_sent'];
            } else {
                $status_updates[] = $messages['test_notification_failed'];
            }

            // Telegram restriction: cannot edit text of a photo message with editMessageText
            // Use editMessageCaption to update photo caption instead
            editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));

            // Delete test file
            unlink($image_path);
        } else {
            $status_updates[] = $messages['screenshot_failed'];
            editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));
        }
    } else {
        $status_updates[] = $messages['page_not_accessible'];
        editTelegramMessage($bot_token, $chat_id, $message_id, $result_header . implode("\n", $status_updates));
    }

    // Final status update
    $final_result_text = $result_header . implode("\n", $status_updates);
    editTelegramMessage($bot_token, $chat_id, $message_id, $final_result_text);

    return $final_result_text; // Return the full text for logging if needed
}

/**
 * Fallback function to perform the test if initial message sending fails.
 * This function duplicates the core logic of testCheck but doesn't send intermediate updates.
 * You might want to refine this or remove it if you prefer to only allow tests with live updates.
 *
 * @param  SQLite3  $db
 * @return string
 */
function _performFullTestAndReturnResult($db)
{
    global $messages;
    $test_result = $messages['test_results_header'];

    // This block should contain the original testCheck logic for content generation
    // without the Telegram message updates.
    $check_url = getSetting($db, 'check_url');
    $data = getCombinedData($check_url, $db);

    if ($data) {
        $test_result .= $messages['page_accessible'] . "\n";
        $generated_on = fetchGeneratedOn($data['content']);
        if ($generated_on) {
            $test_result .= sprintf($messages['timestamp_found'], $generated_on) . "\n";
            $source_timezone = getSetting($db, 'source_timezone');
            $target_timezone = getSetting($db, 'target_timezone');
            $converted_time = convertTimezone($generated_on, $source_timezone, $target_timezone);
            if ($converted_time !== $generated_on &&
                false === strpos($converted_time, 'conversion error') &&
                false === strpos($converted_time, 'conversion failed')) {
                $test_result .= sprintf($messages['timestamp_found'], $converted_time) . "\n";
            } else {
                $test_result .= sprintf($messages['timezone_conversion_failed'], $converted_time) . "\n";
            }
        } else {
            $test_result .= $messages['timestamp_not_found'] . "\n";
        }

        $test_result .= $messages['screenshot_captured'] . "\n";
        $image_path = 'test_screenshot.jpg';
        if (file_put_contents($image_path, base64_decode($data['screenshot']))) {
            $test_caption = $messages['test_notification_caption'];
            $success = sendScreenshot($image_path, $test_caption, $db);
            if ($success) {
                $test_result .= $messages['test_notification_sent'] . "\n";
            } else {
                $test_result .= $messages['test_notification_failed'] . "\n";
            }
            unlink($image_path);
        } else {
            $test_result .= $messages['screenshot_failed'] . "\n";
        }
    } else {
        $test_result .= $messages['page_not_accessible'] . "\n";
    }

    return $test_result;
}

/**
 * Process incoming update from Telegram
 *
 * @param  array  $update
 * @param  string  $bot_token
 * @param  array  $admin_ids
 * @param  SQLite3  $db
 * @return void
 */
function processUpdate($update, $bot_token, $admin_ids, $db)
{
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = $message['text'] ?? '';

        // Handle start command
        if ('/start' === $text) {
            if (isAdmin($user_id, $admin_ids)) {
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    $GLOBALS['messages']['welcome_admin'],
                    createAdminKeyboard()
                );
            } else {
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    $GLOBALS['messages']['access_denied']
                );
            }

            return;
        }

        // Check admin access
        if (! isAdmin($user_id, $admin_ids)) {
            sendTelegramMessage($bot_token, $chat_id, $GLOBALS['messages']['no_access']);

            return;
        }

        // Handle commands
        switch ($text) {
            case $GLOBALS['messages']['show_settings']:
                $settings = getAllSettings($db);
                $response_text = $GLOBALS['messages']['current_settings'];

                foreach ($settings as $key => $data) {
                    $response_text .= sprintf("<b>%s</b>: %s\n<i>%s</i>\n\n", $key, $data['value'], $data['description']);
                }

                sendTelegramMessage($bot_token, $chat_id, $response_text);
                break;

            case $GLOBALS['messages']['edit_setting']:
                $settings = getAllSettings($db);
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    $GLOBALS['messages']['select_setting'],
                    createSettingsKeyboard($settings)
                );
                break;

            case $GLOBALS['messages']['test']:
                // Pass $chat_id to testCheck. testCheck will now handle message updates itself.
                testCheck($db, $chat_id);
                // No need to send another message here as testCheck already handles it.
                break;

            case $GLOBALS['messages']['force_check']:
                $result = processCheck($db, true);
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    $result ? $GLOBALS['messages']['check_completed'] : $GLOBALS['messages']['check_failed']
                );
                break;

            case $GLOBALS['messages']['screenshot_settings_menu']:
                $current_width = getSetting($db, 'viewport_width');
                $current_height = getSetting($db, 'viewport_height');
                $current_quality = getSetting($db, 'image_quality');
                $message = sprintf($GLOBALS['messages']['screenshot_settings'], $current_width, $current_height, $current_quality);
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    $message,
                    createScreenshotSettingsKeyboard()
                );
                break;

            case $GLOBALS['messages']['set_width']:
            case $GLOBALS['messages']['set_height']:
            case $GLOBALS['messages']['set_quality']:
                $setting_key = '';
                if ($text === $GLOBALS['messages']['set_width']) {
                    $setting_key = 'viewport_width';
                }
                if ($text === $GLOBALS['messages']['set_height']) {
                    $setting_key = 'viewport_height';
                }
                if ($text === $GLOBALS['messages']['set_quality']) {
                    $setting_key = 'image_quality';
                }

                file_put_contents("session_{$user_id}.txt", $setting_key);
                $current_value = getSetting($db, $setting_key);
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    sprintf($GLOBALS['messages']['enter_value'], $setting_key, $current_value)
                );
                break;

            case $GLOBALS['messages']['back_to_menu']:
                sendTelegramMessage(
                    $bot_token,
                    $chat_id,
                    $GLOBALS['messages']['back_to_menu'],
                    createAdminKeyboard()
                );
                break;

            default:
                // Check if selecting a setting to edit
                $settings = getAllSettings($db);
                if (array_key_exists($text, $settings)) {
                    file_put_contents("session_{$user_id}.txt", $text);

                    $response_text = sprintf($GLOBALS['messages']['enter_value'], $text, $settings[$text]['value'], $settings[$text]['description']);
                    sendTelegramMessage($bot_token, $chat_id, $response_text);
                    break;
                }

                // Check if updating a setting
                $session_file = "session_{$user_id}.txt";
                if (file_exists($session_file)) {
                    $setting_key = file_get_contents($session_file);

                    updateSetting($db, $setting_key, $text);
                    unlink($session_file);

                    sendTelegramMessage(
                        $bot_token,
                        $chat_id,
                        sprintf($GLOBALS['messages']['setting_updated'], $setting_key, $text),
                        createAdminKeyboard()
                    );
                    break;
                }

                // Default response
                sendTelegramMessage($bot_token, $chat_id, $GLOBALS['messages']['please_select_action'], createAdminKeyboard());
        }
    }
}

/**
 * Get updates from Telegram API
 *
 * @param  string  $bot_token
 * @param  int  $offset
 * @return array
 */
function getUpdates($bot_token, $offset)
{
    $url = "https://api.telegram.org/bot{$bot_token}/getUpdates?offset={$offset}&timeout=30";
    $response = file_get_contents($url);

    return json_decode($response, true) ?? [];
}

// Main execution
$db = initDatabase($db_file);
$update_id = 0;

// Initial check
$initial_check = ! file_exists(getSetting($db, 'cache_file'));
if ($initial_check) {
    processCheck($db);
}

// Main loop
while (true) {
    // Get updates
    $updates = getUpdates($bot_token, $update_id + 1);

    if (isset($updates['result']) && count($updates['result']) > 0) {
        foreach ($updates['result'] as $update) {
            processUpdate($update, $bot_token, $admin_ids, $db);
            $update_id = $update['update_id'];
        }
    }

    // Wait until next check time
    $sleep_time = waitUntilNextHalfHour(false, $db);

    if ($sleep_time > 0) {
        $start = time();
        while (time() - $start < $sleep_time) {
            $updates = getUpdates($bot_token, $update_id + 1);

            if (isset($updates['result']) && count($updates['result']) > 0) {
                foreach ($updates['result'] as $update) {
                    processUpdate($update, $bot_token, $admin_ids, $db);
                    $update_id = $update['update_id'];
                }

                continue;
            }

            sleep(1);
        }
    }

    // Perform periodic check
    processCheck($db);
}
