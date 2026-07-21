<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\ChartArtifact;
use Phptg\BotApi\Type\InputFile;
use Phptg\BotApi\Type\InputMediaPhoto;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\RichBlockCaption;

class AnalyticsChartMediaFactory
{
    public function make(ChartArtifact $artifact): InputRichBlockPhoto
    {
        return new InputRichBlockPhoto(
            photo: new InputMediaPhoto(
                media: new InputFile($artifact->path, 'player-count-history.png'),
            ),
            caption: new RichBlockCaption('История календарных периодов DAY, WEEK и MONTH. Оранжевые точки — частичные периоды.'),
        );
    }
}
