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

$bot_token = getenv('BOT_TOKEN');
$admin_ids_str = getenv('ADMIN_IDS');
$admin_ids = $admin_ids_str ? array_map('intval', explode(',', $admin_ids_str)) : [];
$db_file = getenv('DB_FILE') ?: 'bot_config.db';

if (!$bot_token || empty($admin_ids)) {
    exit("Error! Missing required configuration variables (BOT_TOKEN or ADMIN_IDS).\n");
}

$app = new BotApplication($bot_token, $admin_ids, $db_file);
$app->run();
