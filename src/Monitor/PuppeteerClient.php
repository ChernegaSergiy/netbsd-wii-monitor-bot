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

    public function getCombinedData(string $url, int $maxRetries = 3): array|false
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $serverUrl = $this->settings->get('puppeteer_server') ?? 'http://localhost:3000';
            $viewportWidth = $this->settings->get('viewport_width') ?? 1280;
            $viewportHeight = $this->settings->get('viewport_height') ?? 720;
            $imageQuality = $this->settings->get('image_quality') ?? 80;

            $data = [
                'url' => $url,
                'viewport' => [
                    'width' => (int) $viewportWidth,
                    'height' => (int) $viewportHeight,
                    'quality' => (int) $imageQuality,
                ],
            ];

            $ch = curl_init($serverUrl . '/combined');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 90,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (! $error && 200 === $httpCode) {
                $result = json_decode($response, true);
                if ($result && isset($result['content']) && isset($result['screenshot'])) {
                    return $result;
                }
            }

            error_log("Combined request attempt $attempt failed: " . ($error ?: "HTTP $httpCode"));

            if ($attempt < $maxRetries) {
                sleep(10); // Wait 10 seconds before retrying
            }
        }

        return false;
    }
}
