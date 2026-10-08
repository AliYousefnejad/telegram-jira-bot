<?php

namespace App\Support;

/**
 * Comments are posted with the bot's PAT, so Jira records the PAT account as
 * the author. A footer line naming the linked admin is appended on the way in
 * and recognised again on the way out (in any bot locale) to credit them.
 */
class CommentFooter
{
    private const TRANSLATION_KEY = 'reports.jira.comment_footer';

    private const LOCALES = ['fa', 'en'];

    private const NAME_PLACEHOLDER = "\u{E000}";

    public static function append(string $body, string $name): string
    {
        $footer = self::template(null, $name);

        return $footer !== '' ? $body."\n\n".$footer : $body;
    }

    /**
     * Split a comment into its body and the admin credited by the footer.
     *
     * @return array{body: string, author: ?string}
     */
    public static function extract(string $body): array
    {
        foreach (self::LOCALES as $locale) {
            $template = self::template($locale, self::NAME_PLACEHOLDER);

            if ($template === '' || ! str_contains($template, self::NAME_PLACEHOLDER)) {
                continue;
            }

            $pattern = str_replace(preg_quote(self::NAME_PLACEHOLDER, '/'), '(.+?)', preg_quote($template, '/'));

            if (preg_match('/\s*'.$pattern.'\s*$/u', $body, $matches, PREG_OFFSET_CAPTURE) === 1) {
                return [
                    'body' => rtrim(substr($body, 0, $matches[0][1])),
                    'author' => trim($matches[1][0]) !== '' ? trim($matches[1][0]) : null,
                ];
            }
        }

        return ['body' => $body, 'author' => null];
    }

    private static function template(?string $locale, string $name): string
    {
        $line = __(self::TRANSLATION_KEY, ['name' => $name], $locale);

        return is_string($line) && $line !== self::TRANSLATION_KEY ? $line : '';
    }
}
