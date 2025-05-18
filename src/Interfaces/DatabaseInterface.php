<?php

namespace WiiMonitor\Interfaces;

interface DatabaseInterface
{
    public function getAllSettings() : array;

    public function getSetting(string $key) : ?string;

    public function updateSetting(string $key, string $value) : bool;

    public function init() : void;
}
