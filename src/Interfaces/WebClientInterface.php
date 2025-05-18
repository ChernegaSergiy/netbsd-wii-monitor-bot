<?php

namespace WiiMonitor\Interfaces;

interface WebClientInterface
{
    public function getCombinedData(string $url, array $viewportSettings) : array|false;

    public function takeScreenshot(string $url, array $viewportSettings) : string|false;
}
