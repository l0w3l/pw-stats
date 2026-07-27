<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use Illuminate\Contracts\Translation\Translator;
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
    private readonly TelegramTranslations $translations;

    public function __construct(?TelegramTranslations $translations = null)
    {
        $this->translations = $translations ?? new TelegramTranslations(app(Translator::class));
    }

    public function make(
        LeaderboardAnalyticsData $analytics,
        ?InputRichBlockPhoto $chart = null,
        ?string $locale = null,
    ): InputRichMessage {
        $locale = $this->translations->locale($locale);
        $blocks = [new InputRichBlockSectionHeading(
            $this->translations->get('telegram.headings.digest', $locale),
            1,
        )];

        if ($analytics->isEmpty()) {
            $blocks[] = new InputRichBlockParagraph($this->translations->get('telegram.empty', $locale));

            return new InputRichMessage(blocks: $blocks);
        }

        $blocks[] = new InputRichBlockTable(
            cells: $this->playerCountRows($analytics->playerCountTrends, $locale),
            isBordered: true,
            isStriped: true,
        );

        if ($chart !== null) {
            $blocks[] = $chart;
        }

        return new InputRichMessage(blocks: $blocks);
    }

    /**
     * @param  array<PlayerCountTrendData>  $trends
     * @return array<int, array<int, RichBlockTableCell>>
     */
    private function playerCountRows(array $trends, string $locale): array
    {
        $rows = [$this->headerRow([
            $this->translations->get('telegram.table.period', $locale),
            $this->translations->get('telegram.table.players', $locale),
            $this->translations->get('telegram.table.delta', $locale),
        ])];

        foreach ($trends as $trend) {
            $rows[] = [
                $this->cell($this->translations->get("telegram.periods.{$trend->range}", $locale)),
                $this->cell(number_format($trend->current, 0, '.', ' '), align: 'right'),
                $this->cell($trend->delta === null ? '—' : sprintf('%+d', $trend->delta), align: 'right'),
            ];
        }

        return $rows;
    }

    /** @param list<string> $labels @return array<int, RichBlockTableCell> */
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
}
