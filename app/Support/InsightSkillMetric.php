<?php

namespace App\Support;

class InsightSkillMetric
{
    public const SECTIONS = [
        'earnings_per_skill',
        'rating_per_skill',
        'ranking_per_skill',
        'high_demand_skills',
        'trending_skills',
    ];

    public static function label(array $row): ?string
    {
        $label = $row['label'] ?? $row['name'] ?? null;

        return (is_string($label) && $label !== '') ? $label : null;
    }

    public static function higherIsBetter(string $section): bool
    {
        return ! in_array($section, ['ranking_per_skill', 'trending_skills'], true);
    }

    public static function value(string $section, array $row): ?float
    {
        $raw = $row['displayValue'] ?? $row['value'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $clean = str_replace(',', '', (string) $raw);

        if (! preg_match('/-?\d+(\.\d+)?/', $clean, $m)) {
            return null;
        }

        return (float) $m[0];
    }

    /**
     * @return array{direction: string, number: ?string}
     */
    public static function delta(string $section, ?float $now, ?float $past): array
    {
        if ($now === null || $past === null) {
            return ['direction' => 'even', 'number' => null];
        }

        $diff = $now - $past;

        if (abs($diff) < 1e-9) {
            return ['direction' => 'even', 'number' => self::format($section, 0.0)];
        }

        $improved = self::higherIsBetter($section) ? $diff > 0 : $diff < 0;

        return [
            'direction' => $improved ? 'up' : 'down',
            'number' => self::format($section, abs($diff)),
        ];
    }

    private static function format(string $section, float $magnitude): string
    {
        return match ($section) {
            'earnings_per_skill' => '$' . number_format($magnitude, 0),
            'rating_per_skill' => number_format($magnitude, 1),
            'ranking_per_skill' => number_format($magnitude, 0) . ' pts',
            'high_demand_skills' => number_format($magnitude, 0) . ' pts',
            'trending_skills' => number_format($magnitude, 0) . ' places',
            default => number_format($magnitude, 0),
        };
    }
}
