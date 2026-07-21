<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Models\TelegramNotification;
use Phptg\BotApi\Type\InlineKeyboardButton;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichBlockParagraph;
use Phptg\BotApi\Type\InputRichBlockSectionHeading;
use Phptg\BotApi\Type\InputRichBlockTable;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\RichBlockTableCell;
use Phptg\BotApi\Type\RichTextBold;

class SettingsRichMessageFactory
{
    public const CALLBACK_TOGGLE = 'notifications:toggle:';

    public const CALLBACK_REFRESH = 'notifications:refresh:';

    public function make(
        LeaderboardAnalyticsData $analytics,
        TelegramNotification $subscription,
    ): SettingsView {
        $rows = [[
            $this->cell(new RichTextBold('Период'), true),
            $this->cell(new RichTextBold('Игроки'), true),
            $this->cell(new RichTextBold('Δ'), true),
        ]];

        foreach (['day', 'week', 'month'] as $range) {
            $trend = $this->trend($analytics, $range);
            $rows[] = [
                $this->cell(strtoupper($range)),
                $this->cell($trend === null ? '—' : number_format($trend->current, 0, '.', ' ')),
                $this->cell($trend?->delta === null ? '—' : sprintf('%+d', $trend->delta)),
            ];
        }

        $status = $subscription->enabled ? 'включены' : 'выключены';
        $button = $subscription->enabled ? '🔕 Выключить' : '🔔 Включить';

        return new SettingsView(
            new InputRichMessage(blocks: [
                new InputRichBlockSectionHeading('Pixel World · Настройки', 1),
                new InputRichBlockParagraph('Краткая статистика и ежедневная аналитическая сводка.'),
                new InputRichBlockTable(cells: $rows, isBordered: true, isStriped: true),
                new InputRichBlockParagraph([
                    'Уведомления: ', new RichTextBold($status),
                    '. Время: ', new RichTextBold(substr($subscription->send_time, 0, 5).' UTC'),
                ]),
            ]),
            new InlineKeyboardMarkup([[
                new InlineKeyboardButton($button, callbackData: self::CALLBACK_TOGGLE.$subscription->id),
                new InlineKeyboardButton('🔄 Обновить', callbackData: self::CALLBACK_REFRESH.$subscription->id),
            ]]),
        );
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

    private function cell(string|RichTextBold $text, bool $header = false): RichBlockTableCell
    {
        return new RichBlockTableCell(
            align: 'left',
            valign: 'middle',
            text: $text,
            isHeader: $header ? true : null,
        );
    }
}
