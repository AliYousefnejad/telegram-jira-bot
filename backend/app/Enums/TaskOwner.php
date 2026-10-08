<?php

namespace App\Enums;

enum TaskOwner: string
{
    case L1 = 'L1';

    case L2 = 'L2';

    case Reporter = 'Reporter';

    case CTO = 'CTO';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Picker/button text of each owner option.
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
            self::L1 => 'L1',
            self::L2 => 'L2',
            self::Reporter => 'Reporter',
            self::CTO => 'CTO',
        };
    }

    /** Short badge code, e.g. "L1". */
    public function badge(): string
    {
        return $this->value;
    }

    /**
     * Reverse-map a raw Jira option label (e.g. "L1", "Reporter", or decorated)
     * back to the enum.
     *
     * @param  array<mixed>|string|null  $raw
     */
    public static function fromJiraOption(array|string|null $raw): ?self
    {
        if (is_array($raw)) {
            $raw = $raw[0]['value'] ?? $raw['value'] ?? null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        $upperRaw = mb_strtoupper(trim((string) $raw));

        // Direct case-insensitive match first
        foreach (self::cases() as $case) {
            if (mb_strtoupper($case->value) === $upperRaw) {
                return $case;
            }
        }

        // Substring / word boundary match
        foreach (self::cases() as $case) {
            if (str_contains($upperRaw, mb_strtoupper($case->value))) {
                return $case;
            }
        }

        return null;
    }
}
