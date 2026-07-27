<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\ChartArtifact;
use Illuminate\Contracts\Translation\Translator;
use Phptg\BotApi\Type\InputFile;
use Phptg\BotApi\Type\InputMediaPhoto;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\RichBlockCaption;

class AnalyticsChartMediaFactory
{
    private readonly TelegramTranslations $translations;

    public function __construct(?TelegramTranslations $translations = null)
    {
        $this->translations = $translations ?? new TelegramTranslations(app(Translator::class));
    }

    public function make(ChartArtifact $artifact, ?string $locale = null): InputRichBlockPhoto
    {
        return new InputRichBlockPhoto(
            photo: new InputMediaPhoto(
                media: new InputFile($artifact->path, 'player-count-history.png'),
            ),
            caption: new RichBlockCaption(
                $this->translations->get('telegram.chart.caption', $locale),
            ),
        );
    }
}
