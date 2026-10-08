<?php

namespace App\Support;

class JiraWiki
{
    /**
     * Strips Jira wiki heading markup (e.g. "h3. Heading") so the text can be
     * shown in plain Telegram messages. Bare heading lines are dropped and the
     * "hN. " prefix is removed from lines that carry text after the heading.
     */
    public static function stripHeadings(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);

        if ($lines === false) {
            return $text;
        }

        $clean = [];

        foreach ($lines as $line) {
            if (preg_match('/^h[1-6]\.\s*$/u', $line) === 1) {
                continue;
            }

            $clean[] = preg_replace('/^h[1-6]\.\s*/u', '', $line) ?? $line;
        }

        return implode("\n", $clean);
    }
}
