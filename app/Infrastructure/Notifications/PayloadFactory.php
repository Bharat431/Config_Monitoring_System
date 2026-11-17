<?php

namespace App\Infrastructure\Notifications;

/**
 * Builds provider-specific payloads from the canonical change event.
 */
class PayloadFactory
{
    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    public function build(string $type, array $event): array
    {
        return match (strtolower($type)) {
            'slack'   => $this->slack($event),
            'discord' => $this->discord($event),
            'teams'   => $this->teams($event),
            default   => $event,
        };
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function slack(array $event): array
    {
        $summary = $this->summaryLine($event);

        return [
            'text'   => $summary,
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => '⚙️ Config change: ' . $event['file']],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Type*\n" . $event['type']],
                        ['type' => 'mrkdwn', 'text' => "*Timestamp*\n" . $event['timestamp']],
                        ['type' => 'mrkdwn', 'text' => "*Added*\n" . $event['summary']['added']],
                        ['type' => 'mrkdwn', 'text' => "*Updated*\n" . $event['summary']['updated']],
                        ['type' => 'mrkdwn', 'text' => "*Removed*\n" . $event['summary']['removed']],
                        ['type' => 'mrkdwn', 'text' => "*Type changes*\n" . $event['summary']['type_changes']],
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [[
                        'type' => 'mrkdwn',
                        'text' => 'trace `' . $event['trace_id'] . '` · event `' . $event['event_id'] . '`',
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function discord(array $event): array
    {
        return [
            'content' => $this->summaryLine($event),
            'embeds'  => [[
                'title'       => 'Config change: ' . $event['file'],
                'description' => '```json' . "\n" . $this->jsonSnippet($event) . "\n```",
                'color'       => match ($event['type']) {
                    'created'     => 0x22C55E,
                    'deleted'     => 0xEF4444,
                    'parse_error' => 0xF59E0B,
                    default       => 0x3B82F6,
                },
                'fields' => [
                    ['name' => 'Type', 'value' => (string) $event['type'], 'inline' => true],
                    ['name' => 'Added', 'value' => (string) $event['summary']['added'], 'inline' => true],
                    ['name' => 'Updated', 'value' => (string) $event['summary']['updated'], 'inline' => true],
                    ['name' => 'Removed', 'value' => (string) $event['summary']['removed'], 'inline' => true],
                    ['name' => 'Type changes', 'value' => (string) $event['summary']['type_changes'], 'inline' => true],
                ],
                'footer'    => ['text' => 'trace ' . $event['trace_id']],
                'timestamp' => $event['timestamp'],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function teams(array $event): array
    {
        return [
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'themeColor' => match ($event['type']) {
                'created'     => '22C55E',
                'deleted'     => 'EF4444',
                'parse_error' => 'F59E0B',
                default       => '3B82F6',
            },
            'summary'    => $this->summaryLine($event),
            'title'      => 'Config change: ' . $event['file'],
            'sections'   => [[
                'activityTitle' => $event['file'],
                'facts'         => [
                    ['name' => 'Type', 'value' => (string) $event['type']],
                    ['name' => 'Added', 'value' => (string) $event['summary']['added']],
                    ['name' => 'Updated', 'value' => (string) $event['summary']['updated']],
                    ['name' => 'Removed', 'value' => (string) $event['summary']['removed']],
                    ['name' => 'Type changes', 'value' => (string) $event['summary']['type_changes']],
                    ['name' => 'Event id', 'value' => (string) $event['event_id']],
                ],
                'text' => $this->summaryLine($event),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function summaryLine(array $event): string
    {
        return sprintf(
            '%s %s — +%d added, ~%d updated, -%d removed, !%d type changes',
            (string) $event['file'],
            (string) $event['type'],
            (int) $event['summary']['added'],
            (int) $event['summary']['updated'],
            (int) $event['summary']['removed'],
            (int) $event['summary']['type_changes'],
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function jsonSnippet(array $event): string
    {
        $json = json_encode([
            'file'    => $event['file'],
            'type'    => $event['type'],
            'changes' => $event['changes'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return mb_substr((string) $json, 0, 1600);
    }
}
