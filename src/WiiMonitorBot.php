<?php

namespace WiiMonitor;

use WiiMonitor\Service\AdminPanelService;
use WiiMonitor\Service\MonitoringService;
use WiiMonitor\Interfaces\DatabaseInterface;
use WiiMonitor\Interfaces\TelegramClientInterface;

class WiiMonitorBot
{
    private DatabaseInterface $db;

    private TelegramClientInterface $telegramClient;

    private MonitoringService $monitoringService;

    private AdminPanelService $adminPanelService;

    public function __construct(
        DatabaseInterface $db,
        TelegramClientInterface $telegramClient,
        MonitoringService $monitoringService,
        AdminPanelService $adminPanelService
    ) {
        $this->db = $db;
        $this->telegramClient = $telegramClient;
        $this->monitoringService = $monitoringService;
        $this->adminPanelService = $adminPanelService;
    }

    public function run() : void
    {
        $updateId = 0;

        // Initial check
        $initialCheck = ! file_exists($this->db->getSetting('cache_file'));
        if ($initialCheck) {
            $this->monitoringService->processCheck();
        }

        // Main loop
        while (true) {
            // Process updates
            $updates = $this->telegramClient->getUpdates($updateId + 1);

            if (isset($updates['result']) && count($updates['result']) > 0) {
                foreach ($updates['result'] as $update) {
                    $this->processUpdate($update);
                    $updateId = $update['update_id'];
                }
            }

            // Wait until next check
            $sleepTime = $this->waitUntilNextHalfHour();

            if ($sleepTime > 0) {
                $start = time();
                while (time() - $start < $sleepTime) {
                    $updates = $this->telegramClient->getUpdates($updateId + 1);

                    if (isset($updates['result']) && count($updates['result']) > 0) {
                        foreach ($updates['result'] as $update) {
                            $this->processUpdate($update);
                            $updateId = $update['update_id'];
                        }

                        continue;
                    }

                    sleep(1);
                }
            }

            // Perform periodic check
            $this->monitoringService->processCheck();
        }
    }

    private function processUpdate(array $update) : void
    {
        if (isset($update['message'])) {
            $message = $update['message'];
            $chatId = $message['chat']['id'];
            $userId = $message['from']['id'];
            $text = $message['text'] ?? '';

            $this->adminPanelService->processAdminCommand($chatId, $userId, $text);
        }
    }

    private function waitUntilNextHalfHour() : int
    {
        $now = time();
        $next = strtotime(date('Y-m-d H:00')) + (date('i') < 30 ? 1800 : 3600);

        return $next - $now;
    }
}
