<?php

namespace App\Enums;

enum TaskSeverity: string
{
    case Critical = 'S1';

    case High = 'S2';

    case Medium = 'S3';

    case Low = 'S4';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Picker/button text of each status.
     *
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'S1 – Critical',
            self::High => 'S2 – High',
            self::Medium => 'S3 – Medium',
            self::Low => 'S4 – Low',
        };
    }

    /** Short badge code, e.g. "S1". */
    public function badge(): string
    {
        return $this->value;
    }

    /**
     * Reverse-map a raw Jira option label (e.g. "🔴 S1 - …" or a bare
     * code) back to the enum. Matches on the contained status code, so emoji
     * and dash decorations are irrelevant.
     */
    public static function fromJiraOption(array|string|null $raw): ?self
    {
        if (is_array($raw)) {
            $raw = $raw[0]['value'] ?? null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if (str_contains($raw, $case->value)) {
                return $case;
            }
        }

        // Legacy float-field values were bare numbers (1 = most critical).
        if (is_numeric($raw)) {
            return self::tryFrom('S'.(int) $raw);
        }

        return null;
    }
}
