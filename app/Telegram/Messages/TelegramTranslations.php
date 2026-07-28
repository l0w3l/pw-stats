<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use Illuminate\Contracts\Translation\Translator;

final readonly class TelegramTranslations
{
    private const DEFAULT_LOCALE = 'ru';

    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['ru', 'en'];

    public function __construct(private Translator $translator) {}

    public function locale(?string $locale): string
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true)
            ? $locale
            : self::DEFAULT_LOCALE;
    }

    /** @param array<string, scalar> $replace */
    public function get(string $key, ?string $locale, array $replace = []): string
    {
        $value = $this->translator->get($key, $replace, $this->locale($locale));

        return is_string($value) ? $value : $key;
    }

    public function monthlyKills(int $kills, ?int $players, ?string $locale): string
    {
        if ($players === null || $players < 1) {
            return $this->get('telegram.monthly_kills_without_average', $locale, [
                'kills' => number_format($kills, 0, '.', ' '),
            ]);
        }

        $averageKills = (int) round($kills / $players);

        return $this->get('telegram.monthly_kills', $locale, [
            'kills' => number_format($kills, 0, '.', ' '),
            'average_kills' => number_format($averageKills, 0, '.', ' '),
        ]);
    }

    public function monthlyPlayers(int $kills, int $players, ?string $locale): string
    {
        return $this->get('telegram.monthly_players', $locale, [
            'players' => number_format($players, 0, '.', ' '),
            'time' => $this->formatPlayTime((int) round(($kills * 4) / $players), $this->locale($locale)),
        ]);
    }

    private function formatPlayTime(int $seconds, string $locale): string
    {
        $units = [
            'days' => [86400, intdiv($seconds, 86400)],
            'hours' => [3600, intdiv($seconds % 86400, 3600)],
            'minutes' => [60, intdiv($seconds % 3600, 60)],
            'seconds' => [1, $seconds % 60],
        ];
        $parts = [];

        foreach ($units as $unit => [$size, $value]) {
            if ($value > 0 || ($size === 1 && $parts === [])) {
                $parts[] = $value.' '.$this->get("telegram.duration.{$unit}", $locale);
            }
        }

        return implode(' ', $parts);
    }
}
