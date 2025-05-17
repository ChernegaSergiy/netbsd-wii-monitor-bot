<?php
/**
 * Telegram Website Monitoring Bot
 *
 * This bot monitors a website for changes and sends notifications via Telegram.
 * It checks for timestamp changes and captures screenshots of the monitored site.
 */

declare(strict_types=1);

require_once 'vendor/autoload.php'; // Load libraries via Composer
use Dotenv\Dotenv;

// Error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration
$botToken = getenv('BOT_TOKEN');
$adminIds = explode(',', getenv('ADMIN_IDS'));
$dbFile = getenv('DB_FILE') ?: 'bot_config.db';

// Check if all required environment variables are set
if (! $botToken || empty($adminIds)) {
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
    'test_results' => "📋 <b>Test Results:</b>\n\n",
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
];

/**
 * Initialize database with default settings
 */
function initDatabase(string $dbFile) : SQLite3
{
    $db = new SQLite3($dbFile);

    // Create settings table if doesn't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            description TEXT
        )
    ');

    // Default settings
    $defaultSettings = [
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
    foreach ($defaultSettings as $key => $data) {
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
 */
function getAllSettings(SQLite3 $db) : array
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
 */
function getSetting(SQLite3 $db, string $key) : ?string
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
 */
function updateSetting(SQLite3 $db, string $key, string $value) : bool
{
    $stmt = $db->prepare('UPDATE settings SET value = :value WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);

    return (bool) $stmt->execute();
}

/**
 * Send message via Telegram API
 */
function sendTelegramMessage(string $botToken, int $chatId, string $text, ?string $keyboard = null) : mixed
{
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

    $postData = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    if (null !== $keyboard) {
        $postData['reply_markup'] = $keyboard;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return false;
    }

    return json_decode($response, true);
}

/**
 * Check if user is admin
 */
function isAdmin(int $userId, array $adminIds) : bool
{
    return in_array($userId, $adminIds);
}

/**
 * Create main admin keyboard
 */
function createAdminKeyboard() : string
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
 */
function createScreenshotSettingsKeyboard() : string
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
 */
function createSettingsKeyboard(array $settings) : string
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
 */
function waitUntilNextHalfHour(bool $initialCheck, SQLite3 $db) : int
{
    $now = time();
    $next = strtotime(date('Y-m-d H:00')) + (date('i') < 30 ? 1800 : 3600);

    if ($initialCheck) {
        $cacheFile = getSetting($db, 'cache_file');
        if (! file_exists($cacheFile)) {
            return 0;
        }
    }

    return $next - $now;
}

/**
 * Get combined data (page content and screenshot) from Puppeteer server
 */
function getCombinedData(string $url, SQLite3 $db, int $maxRetries = 3) : array|false
{
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $puppeteerServer = getSetting($db, 'puppeteer_server');
        $viewportWidth = getSetting($db, 'viewport_width');
        $viewportHeight = getSetting($db, 'viewport_height');
        $imageQuality = getSetting($db, 'image_quality');

        $data = [
            'url' => $url,
            'viewport' => [
                'width' => (int) $viewportWidth,
                'height' => (int) $viewportHeight,
                'quality' => (int) $imageQuality,
            ],
        ];

        $ch = curl_init($puppeteerServer . '/combined');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (! $error && 200 === $httpCode) {
            $result = json_decode($response, true);
            if ($result && isset($result['content']) && isset($result['screenshot'])) {
                return $result;
            }
        }

        error_log("Combined request attempt $attempt failed: " . ($error ?: "HTTP $httpCode"));

        if ($attempt < $maxRetries) {
            sleep(10); // Wait 10 seconds before retrying
        }
    }

    return false;
}

/**
 * Fetch generation timestamp from the page content
 */
function fetchGeneratedOn(string $content) : string|false
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
 */
function convertTimezone(string $timestampStr, string $fromTz, string $toTz) : string
{
    try {
        if (preg_match('/^\w{3} \w{3} \d{1,2} \d{2}:\d{2}:\d{2} UTC \d{4}$/', $timestampStr)) {
            $datetime = DateTime::createFromFormat('D M d H:i:s T Y', $timestampStr);
            if (! $datetime) {
                $timestamp = strtotime($timestampStr);
                if (false !== $timestamp) {
                    $datetime = new DateTime;
                    $datetime->setTimestamp($timestamp);
                    $datetime->setTimezone(new DateTimeZone($fromTz));
                }
            }
        } else {
            $timestamp = strtotime($timestampStr);
            if (false !== $timestamp) {
                $datetime = new DateTime;
                $datetime->setTimestamp($timestamp);
                $datetime->setTimezone(new DateTimeZone($fromTz));
            } else {
                $datetime = false;
            }
        }

        if (! $datetime) {
            error_log("Failed to parse timestamp: $timestampStr");

            return $timestampStr . ' (conversion failed)';
        }

        $datetime->setTimezone(new DateTimeZone($toTz));

        return $datetime->format('Y-m-d H:i:s') . " ({$toTz})";
    } catch (Exception $e) {
        error_log('Timezone conversion error: ' . $e->getMessage());

        return $timestampStr . ' (conversion error)';
    }
}

/**
 * Check if timestamp is recent
 */
function isRecent(string $timestampStr, int $minutes, SQLite3 $db) : bool
{
    $sourceTimezone = getSetting($db, 'source_timezone');
    try {
        $dt = new DateTime($timestampStr, new DateTimeZone($sourceTimezone));
        $now = new DateTime('now', new DateTimeZone($sourceTimezone));
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return $diff <= ($minutes * 60);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Take screenshot of the target URL using Puppeteer server
 */
function takeScreenshot(string $targetUrl, SQLite3 $db, int $maxRetries = 3) : string|false
{
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $puppeteerServer = getSetting($db, 'puppeteer_server');
        $viewportWidth = getSetting($db, 'viewport_width');
        $viewportHeight = getSetting($db, 'viewport_height');
        $imageQuality = getSetting($db, 'image_quality');

        $data = [
            'url' => $targetUrl,
            'viewport' => [
                'width' => (int) $viewportWidth,
                'height' => (int) $viewportHeight,
                'quality' => (int) $imageQuality,
            ],
        ];

        $ch = curl_init($puppeteerServer . '/screenshot');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (! $error && 200 === $httpCode) {
            return $response;
        }

        error_log("Screenshot attempt $attempt failed: " . ($error ?: "HTTP $httpCode"));

        if ($attempt < $maxRetries) {
            sleep(10); // Wait 10 seconds before retrying
        }
    }

    return false;
}

/**
 * Send screenshot to Telegram
 */
function sendScreenshot(string $imagePath, string $caption, SQLite3 $db) : bool
{
    global $botToken;
    $chatId = getSetting($db, 'chat_id');

    $sendUrl = "https://api.telegram.org/bot{$botToken}/sendPhoto";
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $sendUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'photo' => new CURLFile($imagePath),
            'caption' => $caption,
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
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
 * Process check logic
 */
function processCheck(SQLite3 $db, bool $force = false) : bool
{
    echo '[ ' . date('H:i:s') . " ] Check started...\n";

    $checkUrl = getSetting($db, 'check_url');
    $cacheFile = getSetting($db, 'cache_file');
    $sourceTimezone = getSetting($db, 'source_timezone');
    $targetTimezone = getSetting($db, 'target_timezone');

    // Get combined data from Puppeteer server
    $data = getCombinedData($checkUrl, $db);
    if (! $data) {
        echo "Failed to get data from Puppeteer server.\n";

        return false;
    }

    // Extract timestamp from content
    $generatedOn = fetchGeneratedOn($data['content']);
    if (! $generatedOn) {
        echo "Generation timestamp not found in page content.\n";

        return false;
    }

    $lastGen = @file_get_contents($cacheFile);

    // Check if timestamp is recent and different from the cached one
    if (! $force && ! isRecent($generatedOn, 30, $db) && $generatedOn !== $lastGen) {
        $convertedTime = convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);
        echo "Generation timestamp is not recent ({$convertedTime}), retrying in 5 minutes...\n";

        return false;
    }

    if ($generatedOn === $lastGen && ! $force) {
        echo "No new generation.\n";

        return true;
    }

    // Save new timestamp
    file_put_contents($cacheFile, $generatedOn);

    $convertedTime = convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);
    echo "Original timestamp: {$generatedOn}\n";
    echo "Converted timestamp: {$convertedTime}\n";

    // Save and send screenshot
    $imagePath = 'screenshot.jpg';
    if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
        $caption = sprintf("New NetBSD Wii build:\nUTC: %s\nLocal: %s",
            $generatedOn,
            $convertedTime
        );

        $success = sendScreenshot($imagePath, $caption, $db);
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

/**
 * Test check functionality
 */
function testCheck(SQLite3 $db) : string
{
    $testResult = $GLOBALS['messages']['test_results'];

    // Test page access and get data
    $checkUrl = getSetting($db, 'check_url');
    $data = getCombinedData($checkUrl, $db);

    if ($data) {
        $testResult .= $GLOBALS['messages']['page_accessible'] . "\n";

        // Test timestamp extraction
        $generatedOn = fetchGeneratedOn($data['content']);
        if ($generatedOn) {
            $testResult .= sprintf($GLOBALS['messages']['timestamp_found'], $generatedOn) . "\n";

            // Test timezone conversion
            $sourceTimezone = getSetting($db, 'source_timezone');
            $targetTimezone = getSetting($db, 'target_timezone');
            $convertedTime = convertTimezone($generatedOn, $sourceTimezone, $targetTimezone);

            if ($convertedTime !== $generatedOn &&
                false === strpos($convertedTime, 'conversion error') &&
                false === strpos($convertedTime, 'conversion failed')) {
                $testResult .= sprintf($GLOBALS['messages']['timestamp_found'], $convertedTime) . "\n";
            } else {
                $testResult .= sprintf($GLOBALS['messages']['timezone_conversion_failed'], $convertedTime) . "\n";
            }
        } else {
            $testResult .= $GLOBALS['messages']['timestamp_not_found'] . "\n";
        }

        // Test screenshot
        $testResult .= $GLOBALS['messages']['screenshot_captured'] . "\n";
        $imagePath = 'test_screenshot.jpg';
        if (file_put_contents($imagePath, base64_decode($data['screenshot']))) {
            // Test notification
            $testCaption = 'Test Notification';
            $success = sendScreenshot($imagePath, $testCaption, $db);
            if ($success) {
                $testResult .= $GLOBALS['messages']['test_notification_sent'] . "\n";
            } else {
                $testResult .= $GLOBALS['messages']['test_notification_failed'] . "\n";
            }

            // Delete the test file
            unlink($imagePath);
        } else {
            $testResult .= $GLOBALS['messages']['screenshot_failed'] . "\n";
        }
    } else {
        $testResult .= $GLOBALS['messages']['page_not_accessible'] . "\n";
    }

    return $testResult;
}

/**
 * Process incoming update from Telegram
 */
function processUpdate(array $update, string $botToken, array $adminIds, SQLite3 $db) : void
{
    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';

        // Handle start command
        if ('/start' === $text) {
            if (isAdmin($userId, $adminIds)) {
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    $GLOBALS['messages']['welcome_admin'],
                    createAdminKeyboard()
                );
            } else {
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    $GLOBALS['messages']['access_denied']
                );
            }

            return;
        }

        // Check admin access
        if (! isAdmin($userId, $adminIds)) {
            sendTelegramMessage($botToken, $chatId, $GLOBALS['messages']['no_access']);

            return;
        }

        // Handle commands
        switch ($text) {
            case $GLOBALS['messages']['show_settings']:
                $settings = getAllSettings($db);
                $responseText = $GLOBALS['messages']['current_settings'];

                foreach ($settings as $key => $data) {
                    $responseText .= sprintf("<b>%s</b>: %s\n<i>%s</i>\n\n", $key, $data['value'], $data['description']);
                }

                sendTelegramMessage($botToken, $chatId, $responseText);
                break;

            case $GLOBALS['messages']['edit_setting']:
                $settings = getAllSettings($db);
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    $GLOBALS['messages']['select_setting'],
                    createSettingsKeyboard($settings)
                );
                break;

            case $GLOBALS['messages']['test']:
                $testResults = testCheck($db);
                sendTelegramMessage($botToken, $chatId, $testResults);
                break;

            case $GLOBALS['messages']['force_check']:
                $result = processCheck($db, true);
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    $result ? $GLOBALS['messages']['check_completed'] : $GLOBALS['messages']['check_failed']
                );
                break;

            case $GLOBALS['messages']['screenshot_settings_menu']:
                $currentWidth = getSetting($db, 'viewport_width');
                $currentHeight = getSetting($db, 'viewport_height');
                $currentQuality = getSetting($db, 'image_quality');
                $message = sprintf($GLOBALS['messages']['screenshot_settings'], $currentWidth, $currentHeight, $currentQuality);
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    $message,
                    createScreenshotSettingsKeyboard()
                );
                break;

            case $GLOBALS['messages']['set_width']:
            case $GLOBALS['messages']['set_height']:
            case $GLOBALS['messages']['set_quality']:
                $settingKey = '';
                if ($text === $GLOBALS['messages']['set_width']) {
                    $settingKey = 'viewport_width';
                }
                if ($text === $GLOBALS['messages']['set_height']) {
                    $settingKey = 'viewport_height';
                }
                if ($text === $GLOBALS['messages']['set_quality']) {
                    $settingKey = 'image_quality';
                }

                file_put_contents("session_{$userId}.txt", $settingKey);
                $currentValue = getSetting($db, $settingKey);
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    sprintf($GLOBALS['messages']['enter_value'], $settingKey, $currentValue)
                );
                break;

            case $GLOBALS['messages']['back_to_menu']:
                sendTelegramMessage(
                    $botToken,
                    $chatId,
                    $GLOBALS['messages']['back_to_menu'],
                    createAdminKeyboard()
                );
                break;

            default:
                // Check if selecting a setting to edit
                $settings = getAllSettings($db);
                if (array_key_exists($text, $settings)) {
                    file_put_contents("session_{$userId}.txt", $text);

                    $responseText = sprintf($GLOBALS['messages']['enter_value'], $text, $settings[$text]['value'], $settings[$text]['description']);
                    sendTelegramMessage($botToken, $chatId, $responseText);
                    break;
                }

                // Check if updating a setting
                $sessionFile = "session_{$userId}.txt";
                if (file_exists($sessionFile)) {
                    $settingKey = file_get_contents($sessionFile);

                    updateSetting($db, $settingKey, $text);
                    unlink($sessionFile);

                    sendTelegramMessage(
                        $botToken,
                        $chatId,
                        sprintf($GLOBALS['messages']['setting_updated'], $settingKey, $text),
                        createAdminKeyboard()
                    );
                    break;
                }

                // Default response
                sendTelegramMessage($botToken, $chatId, $GLOBALS['messages']['please_select_action'], createAdminKeyboard());
        }
    }
}

/**
 * Get updates from Telegram API
 */
function getUpdates(string $botToken, int $offset) : array
{
    $url = "https://api.telegram.org/bot{$botToken}/getUpdates?offset={$offset}&timeout=30";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Telegram API error: $error");

        return [];
    }

    return json_decode($response, true) ?? [];
}

// Main execution
$db = initDatabase($dbFile);
$updateId = 0;

// Initial check
$initialCheck = ! file_exists(getSetting($db, 'cache_file'));
if ($initialCheck) {
    processCheck($db);
}

// Main loop
while (true) {
    // Get updates
    $updates = getUpdates($botToken, $updateId + 1);

    if (isset($updates['result']) && count($updates['result']) > 0) {
        foreach ($updates['result'] as $update) {
            processUpdate($update, $botToken, $adminIds, $db);
            $updateId = $update['update_id'];
        }
    }

    // Wait until next check time
    $sleepTime = waitUntilNextHalfHour(false, $db);

    if ($sleepTime > 0) {
        $start = time();
        while (time() - $start < $sleepTime) {
            $updates = getUpdates($botToken, $updateId + 1);

            if (isset($updates['result']) && count($updates['result']) > 0) {
                foreach ($updates['result'] as $update) {
                    processUpdate($update, $botToken, $adminIds, $db);
                    $updateId = $update['update_id'];
                }

                continue;
            }

            sleep(1);
        }
    }

    // Perform periodic check
    processCheck($db);
}
