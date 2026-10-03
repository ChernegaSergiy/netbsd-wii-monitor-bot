<?php

declare(strict_types=1);

namespace App\Utils;

use App\Config\SettingsManager;
use DateTime;
use DateTimeZone;
use Exception;

class TimeManager
{
    private SettingsManager $settings;

    public function __construct(SettingsManager $settings)
    {
        $this->settings = $settings;
    }

    public function convertTimezone(string $timestamp_str) : string
    {
        $from_tz = $this->settings->get('source_timezone') ?? 'UTC';
        $to_tz = $this->settings->get('target_timezone') ?? 'UTC';

        try {
            if (preg_match('/^\w{3} \w{3} \d{1,2} \d{2}:\d{2}:\d{2} UTC \d{4}$/', $timestamp_str)) {
                $datetime = DateTime::createFromFormat('D M d H:i:s T Y', $timestamp_str);
                if (! $datetime) {
                    $timestamp = strtotime($timestamp_str);
                    if (false !== $timestamp) {
                        $datetime = new DateTime();
                        $datetime->setTimestamp($timestamp);
                        $datetime->setTimezone(new DateTimeZone($from_tz));
                    }
                }
            } else {
                $timestamp = strtotime($timestamp_str);
                if (false !== $timestamp) {
                    $datetime = new DateTime();
                    $datetime->setTimestamp($timestamp);
                    $datetime->setTimezone(new DateTimeZone($from_tz));
                } else {
                    $datetime = false;
                }
            }

            if (! $datetime) {
                error_log("Failed to parse timestamp: $timestamp_str");
                return $timestamp_str . ' (conversion failed)';
            }

            $datetime->setTimezone(new DateTimeZone($to_tz));

            return $datetime->format('Y-m-d H:i:s') . " ({$to_tz})";
        } catch (Exception $e) {
            error_log('Timezone conversion error: ' . $e->getMessage());
            return $timestamp_str . ' (conversion error)';
        }
    }

    public function isRecent(string $timestamp_str, int $minutes) : bool
    {
        $source_tz = $this->settings->get('source_timezone') ?? 'UTC';

        try {
            $dt = new DateTime($timestamp_str, new DateTimeZone($source_tz));
            $now = new DateTime('now', new DateTimeZone($source_tz));
            $diff = $now->getTimestamp() - $dt->getTimestamp();

            return $diff <= ($minutes * 60);
        } catch (Exception $e) {
            return false;
        }
    }

    public function getSleepTime(bool $initial_check) : int
    {
        if ($initial_check) {
            $cache_file = $this->settings->get('cache_file');
            if ($cache_file && !file_exists($cache_file)) {
                return 0;
            }
        }

        $interval = (int) $this->settings->get('check_interval');
        if ($interval <= 0) {
            throw new \App\Exceptions\ConfigurationException('check_interval must be greater than 0');
        }

        return $interval - (time() % $interval);
    }
}
