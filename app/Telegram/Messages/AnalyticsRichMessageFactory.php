<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerActivityData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PlayerMomentumData;
use Illuminate\Support\Str;
use Phptg\BotApi\Type\InputRichBlockDivider;
use Phptg\BotApi\Type\InputRichBlockFooter;
use Phptg\BotApi\Type\InputRichBlockParagraph;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\InputRichBlockSectionHeading;
use Phptg\BotApi\Type\InputRichBlockTable;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\RichBlockTableCell;
use Phptg\BotApi\Type\RichText;
use Phptg\BotApi\Type\RichTextBold;

class AnalyticsRichMessageFactory
{
    public function make(LeaderboardAnalyticsData $analytics, ?InputRichBlockPhoto $chart = null): InputRichMessage
    {
        $blocks = [new InputRichBlockSectionHeading('Pixel World · Статистика', 1)];

        if ($analytics->isEmpty()) {
            $blocks[] = new InputRichBlockParagraph('Завершённых периодов пока нет. Попробуйте снова после первого успешного сбора статистики.');

            return new InputRichMessage(blocks: $blocks);
        }

        if ($analytics->latestCollectedAt) {
            $blocks[] = new InputRichBlockParagraph([
                'Последний успешный сбор: ',
                new RichTextBold($analytics->latestCollectedAt->utc()->format('d.m.Y H:i').' UTC'),
            ]);
        }

        $blocks[] = new InputRichBlockSectionHeading('Динамика игроков', 2);
        $blocks[] = new InputRichBlockParagraph('Изменение числа активных игроков относительно предыдущего календарного периода.');
        $blocks[] = new InputRichBlockTable(
            cells: $this->playerCountRows($analytics->playerCountTrends),
            isBordered: true,
            isStriped: true,
        );

        if ($chart !== null) {
            $blocks[] = $chart;
        }

        $blocks[] = new InputRichBlockDivider;
        $blocks[] = new InputRichBlockSectionHeading('Убийств в день', 2);
        $blocks[] = new InputRichBlockParagraph('Среднее по окну: WEEK ÷ 7, MONTH ÷ 30.');

        if ($analytics->mostActivePlayers === []) {
            $blocks[] = new InputRichBlockParagraph('Недостаточно данных WEEK и MONTH.');
        } else {
            $blocks[] = new InputRichBlockTable(
                cells: $this->activityRows($analytics->mostActivePlayers),
                isBordered: true,
                isStriped: true,
                caption: 'Самые активные игроки',
            );
        }

        $blocks[] = new InputRichBlockDivider;
        $blocks[] = new InputRichBlockSectionHeading('Набирают темп', 2);

        if ($analytics->momentumPlayers === []) {
            $blocks[] = new InputRichBlockParagraph('Для расчёта нужен предыдущий DAY период.');
        } else {
            $blocks[] = new InputRichBlockParagraph(
                'Прирост убийств и движение в рейтинге относительно предыдущего DAY периода.',
            );
            $blocks[] = new InputRichBlockTable(
                cells: $this->momentumRows($analytics->momentumPlayers),
                isBordered: true,
                isStriped: true,
            );
        }

        $blocks[] = new InputRichBlockFooter('Новые данные появляются после завершения очередного сбора.');

        return new InputRichMessage(blocks: $blocks);
    }

    /**
     * @param  array<PlayerCountTrendData>  $trends
     * @return array<int, array<int, RichBlockTableCell>>
     */
    private function playerCountRows(array $trends): array
    {
        $rows = [$this->headerRow(['Период', 'Игроки', 'Δ / интервал'])];

        foreach ($trends as $trend) {
            $change = $trend->delta === null ? '—' : sprintf('%+d', $trend->delta);

            $rows[] = [
                $this->cell($this->rangeLabel($trend->range)),
                $this->cell(number_format($trend->current, 0, '.', ' '), align: 'right'),
                $this->cell($change, align: 'right'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<PlayerActivityData>  $players
     * @return array<int, array<int, RichBlockTableCell>>
     */
    private function activityRows(array $players): array
    {
        $rows = [$this->headerRow(['Период', 'Игрок', 'Уб./день', 'Всего'])];

        foreach ($players as $player) {
            $rows[] = [
                $this->cell(strtoupper($player->range)),
                $this->cell(Str::limit($player->nickname, 20)),
                $this->cell(number_format($player->killsPerDay, 1, '.', ' '), align: 'right'),
                $this->cell(number_format($player->kills, 0, '.', ' '), align: 'right'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<PlayerMomentumData>  $players
     * @return array<int, array<int, RichBlockTableCell>>
     */
    private function momentumRows(array $players): array
    {
        $rows = [$this->headerRow(['Игрок', '+ убийств', 'Места'])];

        foreach ($players as $player) {
            $rank = match (true) {
                $player->rankDelta > 0 => "↑ {$player->rankDelta}",
                $player->rankDelta < 0 => '↓ '.abs($player->rankDelta),
                default => '—',
            };

            $rows[] = [
                $this->cell(Str::limit($player->nickname, 24)),
                $this->cell(sprintf('%+d', $player->killsDelta), align: 'right'),
                $this->cell($rank, align: 'right'),
            ];
        }

        return $rows;
    }

    /** @return array<int, RichBlockTableCell> */
    private function headerRow(array $labels): array
    {
        return array_map(fn (string $label): RichBlockTableCell => $this->cell(
            new RichTextBold($label),
            header: true,
        ), $labels);
    }

    private function cell(
        string|RichText $text,
        bool $header = false,
        string $align = 'left',
    ): RichBlockTableCell {
        return new RichBlockTableCell(
            align: $align,
            valign: 'middle',
            text: $text,
            isHeader: $header ? true : null,
        );
    }

    private function rangeLabel(string $range): string
    {
        return match ($range) {
            'day' => 'DAY',
            'week' => 'WEEK',
            'month' => 'MONTH',
            default => strtoupper($range),
        };
    }
}
