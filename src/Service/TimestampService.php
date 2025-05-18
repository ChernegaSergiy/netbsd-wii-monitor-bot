<?php

namespace WiiMonitor\Service;

use DateTime;
use Exception;
use DateTimeZone;

class TimestampService
{
    public function fetchGeneratedOn(string $content) : string|false
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

    public function convertTimezone(string $timestampStr, string $fromTz, string $toTz) : string
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

    public function isRecent(string $timestampStr, int $minutes, string $sourceTimezone) : bool
    {
        try {
            $dt = new DateTime($timestampStr, new DateTimeZone($sourceTimezone));
            $now = new DateTime('now', new DateTimeZone($sourceTimezone));
            $diff = $now->getTimestamp() - $dt->getTimestamp();

            return $diff <= ($minutes * 60);
        } catch (Exception $e) {
            return false;
        }
    }
}
