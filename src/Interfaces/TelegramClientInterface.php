<?php

namespace WiiMonitor\Interfaces;

interface TelegramClientInterface
{
    public function sendMessage(int $chatId, string $text, ?string $keyboard = null) : mixed;

    public function sendPhoto(int $chatId, string $imagePath, string $caption) : bool;

    public function getUpdates(int $offset) : array;
}
