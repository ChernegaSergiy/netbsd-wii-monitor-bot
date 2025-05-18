<?php

namespace WiiMonitor\Database;

use SQLite3;
use WiiMonitor\Interfaces\DatabaseInterface;

class SQLiteDatabase implements DatabaseInterface
{
    private SQLite3 $db;

    private array $defaultSettings = [
        'check_url' => ['https://blog.infected.systems/status', 'URL to check'],
        'chat_id' => ['-1234567890', 'Chat ID for notifications'],
        'cache_file' => ['last_gen.txt', 'Cache file'],
        'source_timezone' => ['UTC', 'Source timezone'],
        'target_timezone' => ['Europe/Kiev', 'Target timezone'],
        'check_interval' => ['1800', 'Check interval in seconds'],
        'viewport_width' => ['1280', 'Screenshot width in pixels'],
        'viewport_height' => ['720', 'Screenshot height in pixels'],
        'image_quality' => ['80', 'Screenshot quality (1-100)'],
        'puppeteer_server' => ['http://localhost:3000', 'Puppeteer server URL'],
    ];

    public function __construct(string $dbFile)
    {
        $this->db = new SQLite3($dbFile);
    }

    public function init() : void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                description TEXT
            )
        ');

        foreach ($this->defaultSettings as $key => $data) {
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO settings (key, value, description) VALUES (:key, :value, :description)');
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':value', $data[0], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data[1], SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    public function getAllSettings() : array
    {
        $result = $this->db->query('SELECT key, value, description FROM settings');
        $settings = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['key']] = [
                'value' => $row['value'],
                'description' => $row['description'],
            ];
        }

        return $settings;
    }

    public function getSetting(string $key) : ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            return $row['value'];
        }

        return null;
    }

    public function updateSetting(string $key, string $value) : bool
    {
        $stmt = $this->db->prepare('UPDATE settings SET value = :value WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);

        return (bool) $stmt->execute();
    }
}
