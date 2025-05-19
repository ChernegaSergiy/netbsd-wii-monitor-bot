<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use WiiMonitor\WiiMonitorBot;
use WiiMonitor\Client\WebClient;
use WiiMonitor\Service\TestService;
use WiiMonitor\Client\TelegramClient;
use WiiMonitor\Service\ConsoleLogger;
use WiiMonitor\Database\SQLiteDatabase;
use WiiMonitor\Service\KeyboardService;
use WiiMonitor\Service\TimestampService;
use WiiMonitor\Service\AdminPanelService;
use WiiMonitor\Service\MonitoringService;

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize logger
$logger = new ConsoleLogger;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$logger->info('Environment variables loaded');

// Configuration
$botToken = $_ENV['BOT_TOKEN'];
$adminIds = explode(',', $_ENV['ADMIN_IDS']);
$dbFile = $_ENV['DB_FILE'] ?? 'bot_config.db';

// Check required configuration
if (! $botToken || empty($adminIds)) {
    $logger->error('Missing required configuration variables!');
    exit(1);
}

$logger->info('Configuration loaded successfully');

// Load messages
$messages = require __DIR__ . '/../config/messages.php';
$logger->info('Messages loaded');

// Initialize services
$logger->info('Initializing services...');

$db = new SQLiteDatabase($dbFile);
$db->init();
$logger->success('Database initialized');

$telegramClient = new TelegramClient($botToken);
$logger->success('Telegram client initialized');

$webClient = new WebClient($db->getSetting('puppeteer_server'));
$logger->success('Web client initialized');

$timestampService = new TimestampService;
$keyboardService = new KeyboardService($messages);
$logger->success('Utility services initialized');

$monitoringService = new MonitoringService(
    $db,
    $webClient,
    $telegramClient,
    $timestampService
);
$logger->success('Monitoring service initialized');

$testService = new TestService(
    $db,
    $webClient,
    $telegramClient,
    $timestampService,
    $messages
);
$logger->success('Test service initialized');

$adminPanelService = new AdminPanelService(
    $db,
    $telegramClient,
    $keyboardService,
    $testService,
    $monitoringService,
    $messages,
    $adminIds
);
$logger->success('Admin panel service initialized');

// Create bot instance
$bot = new WiiMonitorBot(
    $db,
    $telegramClient,
    $monitoringService,
    $adminPanelService
);

$logger->success('Wii Monitor Bot initialized and ready to start');
$logger->info('Press Ctrl+C to stop the bot');

// Run the bot
$bot->run();
