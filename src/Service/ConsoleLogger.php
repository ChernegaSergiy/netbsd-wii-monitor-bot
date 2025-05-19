<?php

namespace WiiMonitor\Service;

class ConsoleLogger
{
    // ANSI Color codes
    private const COLORS = [
        'info' => "\x1b[36m",    // cyan
        'success' => "\x1b[32m", // green
        'warning' => "\x1b[33m", // yellow
        'error' => "\x1b[31m",   // red
        'reset' => "\x1b[0m",
    ];

    public function info(string $message) : void
    {
        $this->log($message, 'info');
    }

    public function success(string $message) : void
    {
        $this->log($message, 'success');
    }

    public function error(string $message) : void
    {
        $this->log($message, 'error');
    }

    public function warning(string $message) : void
    {
        $this->log($message, 'warning');
    }

    private function log(string $message, string $level) : void
    {
        $timestamp = $this->getTimestamp();
        echo self::COLORS[$level] .
             "[{$timestamp}] {$message}" .
             self::COLORS['reset'] .
             PHP_EOL;
    }

    private function getTimestamp() : string
    {
        $micro = microtime(true);
        $milliseconds = round(($micro - floor($micro)) * 1000);

        // Ensure milliseconds is always 3 digits
        if ($milliseconds >= 1000) {
            $milliseconds = 999;
        }

        return date('Y-m-d\TH:i:', (int) $micro) .
               sprintf('%02d', date('s', (int) $micro)) . ':' .
               sprintf('%03d', $milliseconds) . 'Z';
    }
}
