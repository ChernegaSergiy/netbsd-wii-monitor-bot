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
    private BotClient $bot_client;
    private CommandRouter $router;
    private Checker $checker;
    private TimeManager $time_manager;
    private SettingsManager $settings;

    public function __construct(string $bot_token, array $admin_ids, string $db_file)
    {
        $this->settings = new SettingsManager($db_file);
        $this->bot_client = new BotClient($bot_token);
        $this->time_manager = new TimeManager($this->settings);
        
        $puppeteer = new PuppeteerClient($this->settings);
        $parser = new HtmlParser();
        
        $this->checker = new Checker(
            $this->settings,
            $puppeteer,
            $parser,
            $this->time_manager,
            $this->bot_client
        );
        
        $this->router = new CommandRouter(
            $this->bot_client,
            $this->settings,
            $this->checker,
            $admin_ids
        );
    }

    public function run(): void
    {
        $update_id = 0;
        
        // Initial check
        $initial_check = false;
        $cache_file = $this->settings->get('cache_file');
        if ($cache_file && !file_exists($cache_file)) {
            $initial_check = true;
            $this->checker->processCheck();
        }

        while (true) {
            $updates = $this->bot_client->getUpdates($update_id + 1);

            if (!empty($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $this->router->processUpdate($update);
                    $update_id = $update['update_id'];
                }
            }

            $sleep_time = $this->time_manager->getSleepTime(false);

            if ($sleep_time > 0) {
                $start = time();
                while (time() - $start < $sleep_time) {
                    $updates = $this->bot_client->getUpdates($update_id + 1);

                    if (!empty($updates['result'])) {
                        foreach ($updates['result'] as $update) {
                            $this->router->processUpdate($update);
                            $update_id = $update['update_id'];
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
