<?php

declare(strict_types=1);

namespace App\Monitor;

class HtmlParser
{
    public function fetchGeneratedOn(string $content) : string|false
    {
        $patterns = [
            '/Generated on:\s+([^\n<]+)/',
            '/Generated:\s+([^\n<]+)/',
            '/timestamp[^:]*:\s+([^\n<]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $match)) {
                $timestamp = trim($match[1]);
                return html_entity_decode($timestamp);
            }
        }

        return false;
    }

    public function isScreenshotComplete(string $content) : bool
    {
        $top_start = strpos($content, '=== top ===');

        if (false === $top_start) {
            return false;
        }

        $top_section_content = substr($content, $top_start);

        if (false === strpos($top_section_content, 'load averages:')) {
            return false;
        }

        return true;
    }
}
