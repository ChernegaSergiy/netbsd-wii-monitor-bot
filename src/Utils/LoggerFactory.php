<?php

declare(strict_types=1);

namespace App\Utils;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class LoggerFactory
{
    public static function create(string $name = 'wiim-bot') : LoggerInterface
    {
        $logger = new Logger($name);
        // Log to stdout so Docker can capture it properly
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

        return $logger;
    }
}
