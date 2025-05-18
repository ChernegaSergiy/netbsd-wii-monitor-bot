<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use WiiMonitor\WiiMonitorBot;
use WiiMonitor\Client\WebClient;
use WiiMonitor\Service\TestService;
use WiiMonitor\Client\TelegramClient;
use WiiMonitor\Database\SQLiteDatabase;
use WiiMonitor\Service\KeyboardService;
use WiiMonitor\Service\TimestampService;
use WiiMonitor\Service\AdminPanelService;
use WiiMonitor\Service\MonitoringService;

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Configuration
$botToken = $_ENV['BOT_TOKEN'];
$adminIds = explode(',', $_ENV['ADMIN_IDS']);
$dbFile = $_ENV['DB_FILE'] ?? 'bot_config.db';

// Check required configuration
if (! $botToken || empty($adminIds)) {
    exit("Error! Missing required configuration variables.\n");
}

// Load messages
$messages = require __DIR__ . '/../config/messages.php';

// Initialize services
$db = new SQLiteDatabase($dbFile);
$db->init();

$telegramClient = new TelegramClient($botToken);
$webClient = new WebClient($db->getSetting('puppeteer_server'));
$timestampService = new TimestampService;
$keyboardService = new KeyboardService($messages);

$monitoringService = new MonitoringService(
    $db,
    $webClient,
    $telegramClient,
    $timestampService
);

$testService = new TestService(
    $db,
    $webClient,
    $telegramClient,
    $timestampService,
    $messages
);

$adminPanelService = new AdminPanelService(
    $db,
    $telegramClient,
    $keyboardService,
    $testService,
    $monitoringService,
    $messages,
    $adminIds
);

// Create and run bot
$bot = new WiiMonitorBot(
    $db,
    $telegramClient,
    $monitoringService,
    $adminPanelService
);

$bot->run();
