<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Config\SettingsManager;

class PuppeteerClient
{
    private SettingsManager $settings;

    public function __construct(SettingsManager $settings)
    {
        $this->settings = $settings;
    }

    public function getCombinedData(string $url, int $max_retries = 3) : array|false
    {
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $server_url = $this->settings->get('puppeteer_server') ?? 'http://localhost:3000';
            $viewport_width = $this->settings->get('viewport_width') ?? 1280;
            $viewport_height = $this->settings->get('viewport_height') ?? 720;
            $image_quality = $this->settings->get('image_quality') ?? 80;

            $data = [
                'url' => $url,
                'viewport' => [
                    'width' => (int) $viewport_width,
                    'height' => (int) $viewport_height,
                    'quality' => (int) $image_quality,
                ],
            ];

            $ch = curl_init($server_url . '/combined');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 90,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (! $error && 200 === $http_code) {
                $result = json_decode($response, true);
                if ($result && isset($result['content']) && isset($result['screenshot'])) {
                    return $result;
                }
            }

            error_log("Combined request attempt $attempt failed: " . ($error ?: "HTTP $http_code"));

            if ($attempt < $max_retries) {
                sleep(10); // Wait 10 seconds before retrying
            }
        }

        return false;
    }
}
