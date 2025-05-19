<?php

namespace WiiMonitor\Service;

class ConsoleLogger
{
    // ANSI Color codes
    private const COLORS = [
        'reset' => "\033[0m",
        'black' => "\033[30m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'brightBlack' => "\033[90m",
        'brightRed' => "\033[91m",
        'brightGreen' => "\033[92m",
        'brightYellow' => "\033[93m",
        'brightBlue' => "\033[94m",
        'brightMagenta' => "\033[95m",
        'brightCyan' => "\033[96m",
        'brightWhite' => "\033[97m",
    ];

    public function info(string $message) : void
    {
        $timestamp = $this->getTimestamp();
        echo self::COLORS['brightBlue'] . '[INFO]' .
             self::COLORS['blue'] . "[{$timestamp}] " .
             self::COLORS['white'] . "{$message}" .
             self::COLORS['reset'] . PHP_EOL;
    }

    public function success(string $message) : void
    {
        $timestamp = $this->getTimestamp();
        echo self::COLORS['brightGreen'] . '[SUCCESS]' .
             self::COLORS['green'] . "[{$timestamp}] " .
             self::COLORS['white'] . "{$message}" .
             self::COLORS['reset'] . PHP_EOL;
    }

    public function error(string $message) : void
    {
        $timestamp = $this->getTimestamp();
        echo self::COLORS['brightRed'] . '[ERROR]' .
             self::COLORS['red'] . "[{$timestamp}] " .
             self::COLORS['white'] . "{$message}" .
             self::COLORS['reset'] . PHP_EOL;
    }

    public function warning(string $message) : void
    {
        $timestamp = $this->getTimestamp();
        echo self::COLORS['brightYellow'] . '[WARNING]' .
             self::COLORS['yellow'] . "[{$timestamp}] " .
             self::COLORS['white'] . "{$message}" .
             self::COLORS['reset'] . PHP_EOL;
    }

    private function getTimestamp() : string
    {
        return date('Y-m-d H:i:s');
    }
}
