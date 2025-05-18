<?php

namespace WiiMonitor\Client;

use WiiMonitor\Interfaces\WebClientInterface;

class WebClient implements WebClientInterface
{
    private string $serverUrl;

    public function __construct(string $serverUrl)
    {
        $this->serverUrl = $serverUrl;
    }

    public function getCombinedData(string $url, array $viewportSettings) : array|false
    {
        $data = [
            'url' => $url,
            'viewport' => $viewportSettings,
        ];

        $ch = curl_init($this->serverUrl . '/combined');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (200 === $httpCode) {
            $result = json_decode($response, true);
            if ($result && isset($result['content']) && isset($result['screenshot'])) {
                return $result;
            }
        }

        return false;
    }

    public function takeScreenshot(string $url, array $viewportSettings) : string|false
    {
        $data = [
            'url' => $url,
            'viewport' => $viewportSettings,
        ];

        $ch = curl_init($this->serverUrl . '/screenshot');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return 200 === $httpCode ? $response : false;
    }
}
