<?php

namespace App\Resources;

use App\Enums\TaskOwner;
use App\Support\CommentFooter;
use App\Support\DisplayDate;
use App\Support\JiraWiki;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Renders an issue history as an English, left-to-right "status journey":
 * a summary header followed by one block per status the issue passed
 * through, listing the assignee, owner and comment activity that happened
 * while it sat in that status.
 */
class TimelineResource
{
    private const LOCALE = 'en';

    private const COMMENT_PREVIEW = 120;

    /**
     * Format the full timeline message for an issue.
     *
     * @param  array{
     *     createdAt: string,
     *     createdBy: ?string,
     *     currentStatus: string,
     *     currentAssignee: ?string,
     *     events: array<int, array{
     *         type: string,
     *         timestamp: string,
     *         author: ?string,
     *         from: ?string,
     *         to: ?string,
     *         body?: string
     *     }>
     * }  $changelog
     */
    public static function format(string $issueKey, array $changelog, ?DateTimeInterface $commandTime = null): string
    {
        $now = $commandTime !== null ? Date::parse($commandTime) : now();
        $events = self::withPreviousActivity(self::sortedEvents($changelog['events'] ?? []), (string) ($changelog['createdAt'] ?? ''));
        $journey = self::buildJourney($changelog, $events);

        $lines = [self::t('title', ['key' => $issueKey]), ''];
        array_push($lines, ...self::renderSummary($changelog, $events, $journey, $now));
        $lines[] = '';
        $lines[] = self::t('journey');

        $lastIndex = count($journey) - 1;

        foreach ($journey as $index => $block) {
            $lines[] = '';
            array_push($lines, ...self::renderBlock($index + 1, $block, $index === $lastIndex, $now));
        }

        if ($events === []) {
            $lines[] = '';
            $lines[] = self::t('no_changes');
        }

        return implode("\n", $lines);
    }

    public static function timeAgo(string $from, DateTimeInterface $now): string
    {
        try {
            if ((int) Date::parse($from)->diffInMinutes($now, true) < 1) {
                return self::d('just_now');
            }
        } catch (Throwable) {
            return self::d('just_now');
        }

        return self::t('ago', ['duration' => self::humanDuration($from, $now)]);
    }

    public static function humanDuration(string $from, string|DateTimeInterface $to): string
    {
        try {
            $start = Date::parse($from);
            $end = is_string($to) ? Date::parse($to) : $to;
        } catch (Throwable) {
            return self::d('just_now');
        }

        $diffMinutes = (int) $start->diffInMinutes($end, true);

        if ($diffMinutes < 1) {
            return self::d('just_now');
        }
        if ($diffMinutes < 60) {
            return self::d('minutes', ['count' => $diffMinutes]);
        }

        $hours = intdiv($diffMinutes, 60);
        $minutes = $diffMinutes % 60;

        if ($hours < 24) {
            return $minutes > 0
                ? self::d('hours_minutes', ['hours' => $hours, 'minutes' => $minutes])
                : self::d('hours', ['count' => $hours]);
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return $remainingHours > 0
            ? self::d('days_hours', ['days' => $days, 'hours' => $remainingHours])
            : self::d('days', ['count' => $days]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private static function sortedEvents(array $events): array
    {
        $indexed = array_values($events);

        // Stable chronological order; unparseable timestamps sink to the end.
        uksort($indexed, function (int $a, int $b) use ($indexed): int {
            $diff = self::epoch((string) ($indexed[$a]['timestamp'] ?? '')) <=> self::epoch((string) ($indexed[$b]['timestamp'] ?? ''));

            return $diff !== 0 ? $diff : $a <=> $b;
        });

        return array_values($indexed);
    }

    /**
     * Stamp every event with the time of the activity before it (creation for
     * the first one), so each line can show how long the issue waited.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private static function withPreviousActivity(array $events, string $createdAt): array
    {
        $previous = $createdAt;

        foreach ($events as $i => $event) {
            $events[$i]['previousAt'] = $previous;
            $timestamp = (string) ($event['timestamp'] ?? '');

            if ($timestamp !== '') {
                $previous = $timestamp;
            }
        }

        return $events;
    }

    /**
     * One block per status: the creation status first, then one per status change.
     *
     * @param  array<string, mixed>  $changelog
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>> blocks of status, enteredAt, enteredBy, isCreation, activity, until
     */
    private static function buildJourney(array $changelog, array $events): array
    {
        $statusEvents = self::ofType($events, 'status');

        $initialStatus = $statusEvents !== []
            ? (string) ($statusEvents[0]['from'] ?? '')
            : (string) ($changelog['currentStatus'] ?? '');

        $journey = [[
            'status' => $initialStatus,
            'enteredAt' => (string) ($changelog['createdAt'] ?? ''),
            'enteredBy' => $changelog['createdBy'] ?? null,
            'isCreation' => true,
            'activity' => [],
        ]];

        foreach ($events as $event) {
            if (($event['type'] ?? '') === 'status') {
                $journey[] = [
                    'status' => (string) ($event['to'] ?? ''),
                    'enteredAt' => (string) ($event['timestamp'] ?? ''),
                    'enteredBy' => $event['author'] ?? null,
                    'isCreation' => false,
                    'activity' => [],
                ];

                continue;
            }

            // Assignee, owner and comment activity belongs to whichever status was active at the time.
            $journey[count($journey) - 1]['activity'][] = $event;
        }

        // A status lasts until the next one is entered; the last runs until now.
        foreach (array_keys($journey) as $i) {
            $journey[$i]['until'] = $journey[$i + 1]['enteredAt'] ?? null;
        }

        return $journey;
    }

    /**
     * @param  array<string, mixed>  $changelog
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $journey
     * @return array<int, string>
     */
    private static function renderSummary(array $changelog, array $events, array $journey, CarbonInterface $now): array
    {
        $current = $journey[count($journey) - 1];
        $currentStatus = self::orUnknown((string) ($changelog['currentStatus'] ?? ''));
        $enteredAt = (string) $current['enteredAt'];

        $lines = [
            $enteredAt !== ''
                ? self::t('now', ['status' => $currentStatus, 'duration' => self::humanDuration($enteredAt, $now)])
                : self::t('now_no_duration', ['status' => $currentStatus]),
            self::t('assignee', ['assignee' => self::assigneeName($changelog['currentAssignee'] ?? null)]),
        ];

        $ownerEvents = self::ofType($events, 'owner');

        if ($ownerEvents !== []) {
            $lines[] = self::t('owner', ['owner' => self::ownerName($ownerEvents[count($ownerEvents) - 1]['to'] ?? null)]);
        }

        $createdAt = (string) ($changelog['createdAt'] ?? '');

        if ($createdAt !== '') {
            $lines[] = self::t('created', [
                'date' => self::formatJalaliDateTime($createdAt),
                'author' => self::orUnknown((string) ($changelog['createdBy'] ?? '')),
                'ago' => self::timeAgo($createdAt, $now),
            ]);
        }

        if ($events !== []) {
            $lines[] = self::renderLastChange($events[count($events) - 1], $now);
        }

        $comments = self::ofType($events, 'comment');

        $lines[] = self::t('changes', [
            'status' => count(self::ofType($events, 'status')),
            'assignee' => count(self::ofType($events, 'assignee')),
            'owner' => count($ownerEvents),
            'comment' => count($comments),
        ]);

        $firstCommentAt = (string) ($comments[0]['timestamp'] ?? '');

        if ($createdAt !== '' && $firstCommentAt !== '') {
            $lines[] = self::t('first_response', ['duration' => self::humanDuration($createdAt, $firstCommentAt)]);
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $event */
    private static function renderLastChange(array $event, CarbonInterface $now): string
    {
        $author = self::eventAuthor($event);

        $change = match ((string) ($event['type'] ?? '')) {
            'status' => self::t('last_status', ['status' => self::orUnknown((string) ($event['to'] ?? '')), 'author' => $author]),
            'assignee' => self::t('last_assignee', ['assignee' => self::assigneeName($event['to'] ?? null), 'author' => $author]),
            'owner' => self::t('last_owner', ['owner' => self::ownerName($event['to'] ?? null), 'author' => $author]),
            default => self::t('last_comment', ['author' => $author]),
        };

        $timestamp = (string) ($event['timestamp'] ?? '');

        return self::t('last_change', [
            'change' => $change,
            'ago' => $timestamp !== '' ? self::timeAgo($timestamp, $now) : '—',
        ]);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<int, string>
     */
    private static function renderBlock(int $number, array $block, bool $isCurrent, CarbonInterface $now): array
    {
        $enteredAt = (string) $block['enteredAt'];
        $until = ! $isCurrent && ($block['until'] ?? '') !== '' ? (string) $block['until'] : $now;
        $duration = $enteredAt !== '' ? self::humanDuration($enteredAt, $until) : '—';

        $lines = [self::t($isCurrent ? 'block_current' : 'block', [
            'n' => $number,
            'status' => self::orUnknown((string) $block['status']),
            'duration' => $duration,
        ])];

        $lines[] = self::t($block['isCreation'] ? 'created_by' : 'moved_by', [
            'date' => self::formatJalaliDateTime($enteredAt),
            'author' => self::orUnknown((string) ($block['enteredBy'] ?? '')),
        ]);

        foreach ($block['activity'] as $event) {
            array_push($lines, ...self::renderActivity($event));
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<int, string>
     */
    private static function renderActivity(array $event): array
    {
        $timestamp = (string) ($event['timestamp'] ?? '');
        $date = self::formatJalaliDateTime($timestamp);
        $author = self::eventAuthor($event);

        if (($event['type'] ?? '') === 'comment') {
            $previousAt = (string) ($event['previousAt'] ?? '');
            $lines = [self::t('comment', [
                'date' => $date,
                'author' => $author,
                'gap' => $previousAt !== '' && $timestamp !== '' ? self::humanDuration($previousAt, $timestamp) : '—',
            ])];

            $preview = self::commentPreview(CommentFooter::extract((string) ($event['body'] ?? ''))['body']);

            if ($preview !== '') {
                $lines[] = self::t('comment_body', ['body' => $preview]);
            }

            return $lines;
        }

        $isOwner = ($event['type'] ?? '') === 'owner';

        return [
            $isOwner
                ? self::t('owner_change', ['from' => self::ownerName($event['from'] ?? null), 'to' => self::ownerName($event['to'] ?? null)])
                : self::t('assignee_change', ['from' => self::assigneeName($event['from'] ?? null), 'to' => self::assigneeName($event['to'] ?? null)]),
            self::t('activity_by', ['date' => $date, 'author' => $author]),
        ];
    }

    /**
     * Bot-posted comments carry the PAT account as author; credit the admin
     * named in their footer instead.
     *
     * @param  array<string, mixed>  $event
     */
    private static function eventAuthor(array $event): string
    {
        if (($event['type'] ?? '') === 'comment') {
            $credited = CommentFooter::extract((string) ($event['body'] ?? ''))['author'];

            if ($credited !== null) {
                return $credited;
            }
        }

        return self::orUnknown((string) ($event['author'] ?? ''));
    }

    /** One-line, markup-free excerpt of a Jira wiki comment. */
    private static function commentPreview(string $body): string
    {
        $text = JiraWiki::stripHeadings($body);
        $text = (string) preg_replace('/\[~([^\]]+)\]/u', '@$1', $text);
        $text = (string) preg_replace('/\[([^|\]]+)\|[^\]]+\]/u', '$1', $text);
        $text = (string) preg_replace('/\{(code|noformat|quote|panel|color)[^}]*\}/u', '', $text);
        $text = str_replace(['*', '_', '{', '}', '[', ']'], '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::COMMENT_PREVIEW
            ? rtrim(mb_substr($text, 0, self::COMMENT_PREVIEW)).'…'
            : $text;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private static function ofType(array $events, string $type): array
    {
        return array_values(array_filter($events, fn (array $e): bool => ($e['type'] ?? '') === $type));
    }

    private static function formatJalaliDateTime(string $isoTimestamp): string
    {
        return DisplayDate::format($isoTimestamp) ?? '—';
    }

    private static function epoch(string $timestamp): int
    {
        try {
            return $timestamp !== '' ? Date::parse($timestamp)->getTimestamp() : PHP_INT_MAX;
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    private static function assigneeName(mixed $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : self::t('unassigned');
    }

    private static function ownerName(mixed $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return '—';
        }

        return TaskOwner::fromJiraOption($raw)?->label() ?? $raw;
    }

    private static function orUnknown(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? $value : self::t('unknown');
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $key, array $replace = []): string
    {
        return self::translate("timeline.report.{$key}", $replace);
    }

    /** @param  array<string, string|int>  $replace */
    private static function d(string $key, array $replace = []): string
    {
        return self::translate("timeline.duration.{$key}", $replace);
    }

    /** @param  array<string, string|int>  $replace */
    private static function translate(string $key, array $replace): string
    {
        $line = __($key, $replace, self::LOCALE);

        return is_string($line) ? $line : $key;
    }
}
