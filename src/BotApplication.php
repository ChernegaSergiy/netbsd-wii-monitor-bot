<?php

declare(strict_types=1);

namespace App;

use App\Config\SettingsManager;
use App\Monitor\Checker;
use App\Monitor\HtmlParser;
use App\Monitor\PuppeteerClient;
use App\Telegram\BotClient;
use App\Telegram\CommandRouter;
use App\Utils\TimeManager;

class BotApplication
{
    private BotClient $botClient;
    private CommandRouter $router;
    private Checker $checker;
    private TimeManager $timeManager;
    private SettingsManager $settings;

    public function __construct(string $botToken, array $adminIds, string $dbFile)
    {
        $this->settings = new SettingsManager($dbFile);
        $this->botClient = new BotClient($botToken);
        $this->timeManager = new TimeManager($this->settings);
        
        $puppeteer = new PuppeteerClient($this->settings);
        $parser = new HtmlParser();
        
        $this->checker = new Checker(
            $this->settings,
            $puppeteer,
            $parser,
            $this->timeManager,
            $this->botClient
        );
        
        $this->router = new CommandRouter(
            $this->botClient,
            $this->settings,
            $this->checker,
            $adminIds
        );
    }

    public function run(): void
    {
        $updateId = 0;
        
        // Initial check
        $initialCheck = false;
        $cacheFile = $this->settings->get('cache_file');
        if ($cacheFile && !file_exists($cacheFile)) {
            $initialCheck = true;
            $this->checker->processCheck();
        }

        while (true) {
            $updates = $this->botClient->getUpdates($updateId + 1);

            if (!empty($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $this->router->processUpdate($update);
                    $updateId = $update['update_id'];
                }
            }

            $sleepTime = $this->timeManager->getSleepTime(false);

            if ($sleepTime > 0) {
                $start = time();
                while (time() - $start < $sleepTime) {
                    $updates = $this->botClient->getUpdates($updateId + 1);

                    if (!empty($updates['result'])) {
                        foreach ($updates['result'] as $update) {
                            $this->router->processUpdate($update);
                            $updateId = $update['update_id'];
                        }
                        continue;
                    }

                    sleep(1);
                }
            }

            $this->checker->processCheck();
        }
    }
}
