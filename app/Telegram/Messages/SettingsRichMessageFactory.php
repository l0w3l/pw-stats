<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PointsThresholdData;
use App\Models\TelegramNotification;
use Illuminate\Contracts\Translation\Translator;
use Phptg\BotApi\Type\InlineKeyboardButton;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichBlockParagraph;
use Phptg\BotApi\Type\InputRichBlockSectionHeading;
use Phptg\BotApi\Type\InputRichBlockTable;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\RichBlockTableCell;
use Phptg\BotApi\Type\RichText;
use Phptg\BotApi\Type\RichTextBold;

class SettingsRichMessageFactory
{
    public const CALLBACK_TOGGLE = 'notifications:toggle:';

    public const CALLBACK_REFRESH = 'notifications:refresh:';

    public const CALLBACK_LOCALE = 'notifications:locale:';

    public const CALLBACK_FREQUENCY = 'notifications:frequency:';

    private readonly TelegramTranslations $translations;

    public function __construct(?TelegramTranslations $translations = null)
    {
        $this->translations = $translations ?? new TelegramTranslations(app(Translator::class));
    }

    public function make(
        LeaderboardAnalyticsData $analytics,
        TelegramNotification $subscription,
        ?string $locale = null,
        ?int $monthlyKills = null,
    ): SettingsView {
        $locale = $this->translations->locale($locale);
        $blocks = [new InputRichBlockSectionHeading(
            $this->translations->get('telegram.headings.settings', $locale),
            1,
        )];

        if ($analytics->isEmpty()) {
            $blocks[] = new InputRichBlockParagraph($this->translations->get('telegram.empty', $locale));
        } else {
            $blocks[] = new InputRichBlockTable(
                cells: $this->playerCountRows($analytics, $locale),
                isBordered: true,
                isStriped: true,
                caption: $this->translations->get('telegram.table_titles.player_counts', $locale),
            );
            $blocks[] = new InputRichBlockTable(
                cells: $this->pointsThresholdRows($analytics, $locale),
                isBordered: true,
                isStriped: true,
                caption: $this->translations->get('telegram.table_titles.points_thresholds', $locale),
            );
        }
        if ($monthlyKills !== null) {
            $blocks[] = new InputRichBlockParagraph($this->translations->monthlyKills($monthlyKills, $locale));
        }

        return new SettingsView(
            new InputRichMessage(blocks: $blocks),
            $this->keyboard($subscription, $locale),
        );
    }

    /** @return array<int, array<int, RichBlockTableCell>> */
    private function playerCountRows(LeaderboardAnalyticsData $analytics, string $locale): array
    {
        $rows = [$this->headerRow([
            $this->translations->get('telegram.table.period', $locale),
            $this->translations->get('telegram.table.players', $locale),
            $this->translations->get('telegram.table.delta', $locale),
        ])];

        foreach (['day', 'week', 'month'] as $range) {
            $trend = $this->trend($analytics, $range);
            $rows[] = [
                $this->cell($this->translations->get("telegram.periods.{$range}", $locale)),
                $this->cell($trend === null ? '—' : number_format($trend->current, 0, '.', ' ')),
                $this->cell($trend?->delta === null ? '—' : sprintf('%+d', $trend->delta)),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<int, RichBlockTableCell>> */
    private function pointsThresholdRows(LeaderboardAnalyticsData $analytics, string $locale): array
    {
        $rows = [$this->headerRow([
            $this->translations->get('telegram.table.points', $locale),
            'DAY',
            'WEEK',
            'MONTH',
        ])];

        foreach ([250, 100, 50] as $minimumPoints) {
            $rows[] = [
                $this->cell("{$minimumPoints}+"),
                ...array_map(
                    fn (string $range): RichBlockTableCell => $this->cell(
                        $this->formatThreshold($this->threshold($analytics, $range), $minimumPoints),
                    ),
                    ['day', 'week', 'month'],
                ),
            ];
        }

        return $rows;
    }

    private function keyboard(TelegramNotification $subscription, string $locale): InlineKeyboardMarkup
    {
        $toggle = $subscription->enabled ? 'disable' : 'enable';

        return new InlineKeyboardMarkup([
            [
                new InlineKeyboardButton(
                    $this->translations->get("telegram.controls.{$toggle}", $locale),
                    callbackData: self::CALLBACK_TOGGLE.$subscription->id,
                ),
                new InlineKeyboardButton(
                    $this->translations->get('telegram.controls.refresh', $locale),
                    callbackData: self::CALLBACK_REFRESH.$subscription->id,
                ),
            ],
            [
                new InlineKeyboardButton('RU', callbackData: self::CALLBACK_LOCALE.'ru:'.$subscription->id),
                new InlineKeyboardButton('EN', callbackData: self::CALLBACK_LOCALE.'en:'.$subscription->id),
            ],
            array_map(
                fn (string $frequency): InlineKeyboardButton => new InlineKeyboardButton(
                    ($subscription->frequency === $frequency ? '✓ ' : '')
                        .$this->translations->get("telegram.frequencies.{$frequency}", $locale),
                    callbackData: self::CALLBACK_FREQUENCY.$frequency.':'.$subscription->id,
                ),
                TelegramNotification::FREQUENCIES,
            ),
        ]);
    }

    private function trend(LeaderboardAnalyticsData $analytics, string $range): ?PlayerCountTrendData
    {
        foreach ($analytics->playerCountTrends as $trend) {
            if ($trend->range === $range) {
                return $trend;
            }
        }

        return null;
    }

    private function threshold(LeaderboardAnalyticsData $analytics, string $range): ?PointsThresholdData
    {
        foreach ($analytics->pointsThresholds as $threshold) {
            if ($threshold->range === $range) {
                return $threshold;
            }
        }

        return null;
    }

    private function formatThreshold(?PointsThresholdData $threshold, int $minimumPoints): string
    {
        $value = match ($minimumPoints) {
            50 => $threshold?->playersAtLeast50,
            100 => $threshold?->playersAtLeast100,
            250 => $threshold?->playersAtLeast250,
        };

        return $value === null ? '—' : (string) $value;
    }

    /** @param list<string> $labels @return array<int, RichBlockTableCell> */
    private function headerRow(array $labels): array
    {
        return array_map(fn (string $label): RichBlockTableCell => $this->cell(
            new RichTextBold($label),
            header: true,
        ), $labels);
    }

    private function cell(string|RichText $text, bool $header = false): RichBlockTableCell
    {
        return new RichBlockTableCell(
            align: 'left',
            valign: 'middle',
            text: $text,
            isHeader: $header ? true : null,
        );
    }
}
