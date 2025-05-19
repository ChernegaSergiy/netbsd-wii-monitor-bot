<?php

namespace WiiMonitor\Client;

use WiiMonitor\Service\ConsoleLogger;
use WiiMonitor\Interfaces\WebClientInterface;

class WebClient implements WebClientInterface
{
    private string $serverUrl;

    private ConsoleLogger $logger;

    public function __construct(string $serverUrl)
    {
        $this->serverUrl = $serverUrl;
        $this->logger = new ConsoleLogger;
    }

    public function getCombinedData(string $url, array $viewportSettings) : array|false
    {
        $this->logger->info("Requesting combined data for URL: {$url}");

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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("CURL error: {$error}");

            return false;
        }

        if (200 === $httpCode) {
            $result = json_decode($response, true);
            if ($result && isset($result['content']) && isset($result['screenshot'])) {
                $this->logger->success('Combined data received successfully');

                return $result;
            }
            $this->logger->error('Invalid response format from server');
        }

        $this->logger->error("Failed to get combined data. HTTP code: {$httpCode}");

        return false;
    }

    public function takeScreenshot(string $url, array $viewportSettings) : string|false
    {
        $this->logger->info("Taking screenshot of URL: {$url}");

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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("CURL error: {$error}");

            return false;
        }

        if (200 === $httpCode) {
            $this->logger->success('Screenshot taken successfully');

            return $response;
        }

        $this->logger->error("Failed to take screenshot. HTTP code: {$httpCode}");

        return false;
    }
}
