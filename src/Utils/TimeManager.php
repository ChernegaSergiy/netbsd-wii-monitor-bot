<?php

declare(strict_types=1);

namespace App\Utils;

use DateTime;
use DateTimeZone;
use Exception;
use App\Config\SettingsManager;

class TimeManager
{
    private SettingsManager $settings;

    public function __construct(SettingsManager $settings)
    {
        $this->settings = $settings;
    }

    public function convertTimezone(string $timestampStr): string
    {
        $fromTz = $this->settings->get('source_timezone') ?? 'UTC';
        $toTz = $this->settings->get('target_timezone') ?? 'UTC';

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

    public function isRecent(string $timestampStr, int $minutes): bool
    {
        $sourceTz = $this->settings->get('source_timezone') ?? 'UTC';
        
        try {
            $dt = new DateTime($timestampStr, new DateTimeZone($sourceTz));
            $now = new DateTime('now', new DateTimeZone($sourceTz));
            $diff = $now->getTimestamp() - $dt->getTimestamp();

            return $diff <= ($minutes * 60);
        } catch (Exception $e) {
            return false;
        }
    }

    public function getSleepTime(bool $initialCheck): int
    {
        if ($initialCheck) {
            $cacheFile = $this->settings->get('cache_file');
            if ($cacheFile && !file_exists($cacheFile)) {
                return 0;
            }
        }

        $interval = (int) $this->settings->get('check_interval');
        if ($interval <= 0) {
            $interval = 900;
        }

        return $interval - (time() % $interval);
    }
}
