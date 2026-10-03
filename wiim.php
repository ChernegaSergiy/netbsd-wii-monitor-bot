<?php

/**
 * Telegram Website Monitoring Bot
 *
 * This bot monitors a website for changes and sends notifications via Telegram.
 * Refactored using OOP.
 */

declare(strict_types=1);

require_once 'vendor/autoload.php';
use Dotenv\Dotenv;
use App\BotApplication;

// Error reporting for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$botToken = getenv('BOT_TOKEN');
$adminIdsStr = getenv('ADMIN_IDS');
$adminIds = $adminIdsStr ? array_map('intval', explode(',', $adminIdsStr)) : [];
$dbFile = getenv('DB_FILE') ?: 'bot_config.db';

if (!$botToken || empty($adminIds)) {
    exit("Error! Missing required configuration variables (BOT_TOKEN or ADMIN_IDS).\n");
}

$app = new BotApplication($botToken, $adminIds, $dbFile);
$app->run();
