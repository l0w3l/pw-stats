<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Charts;

use App\Data\PixelWorld\Analytics\PlayerCountChartData;
use App\Data\PixelWorld\Analytics\PlayerCountChartSeries;
use Imagick;
use ImagickPixel;
use RuntimeException;

class ImagickSvgChartRenderer implements ChartRenderer
{
    public function version(): string
    {
        return 'imagick-svg-v1';
    }

    public function render(PlayerCountChartData $data, int $width, int $height): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('The Imagick extension is required to render analytics charts.');
        }

        $this->limitResources();

        $image = new Imagick;
        $image->setBackgroundColor(new ImagickPixel('#ffffff'));
        $image->setResolution(96, 96);
        $image->setSize($width, $height);
        $image->readImageBlob($this->svg($data, $width, $height));
        $image->setImageBackgroundColor(new ImagickPixel('#ffffff'));
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->setImageDepth(8);
        $image->setImageFormat('png');
        $image->stripImage();
        $png = $image->getImagesBlob();
        $image->clear();

        if ($png === '') {
            throw new RuntimeException('Imagick produced an empty chart.');
        }

        return $png;
    }

    private function limitResources(): void
    {
        foreach ([
            Imagick::RESOURCETYPE_MEMORY => 128 * 1024 * 1024,
            Imagick::RESOURCETYPE_MAP => 256 * 1024 * 1024,
            Imagick::RESOURCETYPE_DISK => 512 * 1024 * 1024,
            Imagick::RESOURCETYPE_THREAD => 1,
        ] as $type => $limit) {
            Imagick::setResourceLimit($type, $limit);
        }
    }

    private function svg(PlayerCountChartData $data, int $width, int $height): string
    {
        $panelGap = 14;
        $outer = 28;
        $titleHeight = 48;
        $panelHeight = ($height - $outer * 2 - $titleHeight - $panelGap * 2) / 3;
        $parts = [sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#f8fafc"/><style>text{font-family:Arial,sans-serif;fill:#334155}.title{font-size:25px;font-weight:700}.label{font-size:15px;font-weight:700}.axis{font-size:12px;fill:#64748b}</style><text x="%d" y="38" class="title">История числа игроков по календарным периодам</text>',
            $width,
            $height,
            $width,
            $height,
            $outer,
        )];

        foreach ($data->series as $index => $series) {
            $y = $outer + $titleHeight + $index * ($panelHeight + $panelGap);
            $parts[] = $this->panel($series, $outer, $y, $width - $outer * 2, $panelHeight);
        }

        $parts[] = '</svg>';

        return implode('', $parts);
    }

    private function panel(PlayerCountChartSeries $series, float $x, float $y, float $width, float $height): string
    {
        $label = $this->escape(strtoupper($series->range));
        $parts = [sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="10" fill="#fff" stroke="#e2e8f0"/><text x="%.1f" y="%.1f" class="label">%s</text>', $x, $y, $width, $height, $x + 16, $y + 25, $label)];

        if ($series->points === []) {
            return implode('', $parts).sprintf('<text x="%.1f" y="%.1f" class="axis">Нет данных</text>', $x + 70, $y + 25);
        }

        $plotX = $x + 105;
        $plotY = $y + 18;
        $plotWidth = $width - 125;
        $plotHeight = $height - 42;
        $totals = array_map(static fn ($point): int => $point->total, $series->points);
        $minimum = min($totals);
        $maximum = max($totals);
        $padding = max(1, (int) ceil(($maximum - $minimum) * 0.1));
        $minimum -= $padding;
        $maximum += $padding;
        $span = max(1, $maximum - $minimum);
        $lastIndex = max(1, count($series->points) - 1);
        $coordinates = [];

        $parts[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#e2e8f0"/><text x="%.1f" y="%.1f" text-anchor="end" class="axis">%s</text><text x="%.1f" y="%.1f" text-anchor="end" class="axis">%s</text>', $plotX, $plotY + $plotHeight, $plotX + $plotWidth, $plotY + $plotHeight, $plotX - 8, $plotY + 5, number_format($maximum, 0, '.', ' '), $plotX - 8, $plotY + $plotHeight, number_format($minimum, 0, '.', ' '));

        foreach ($series->points as $index => $point) {
            $pointX = $plotX + $index / $lastIndex * $plotWidth;
            $pointY = $plotY + ($maximum - $point->total) / $span * $plotHeight;
            $coordinates[] = sprintf('%.1f,%.1f', $pointX, $pointY);

            if ($point->isPartial) {
                $parts[] = sprintf('<circle cx="%.1f" cy="%.1f" r="5" fill="#fff" stroke="#f59e0b" stroke-width="3"/>', $pointX, $pointY);
            }
        }

        $first = $series->points[0]->periodStart->format('d.m.Y');
        $last = $series->points[array_key_last($series->points)]->periodStart->format('d.m.Y');
        $parts[] = sprintf('<polyline points="%s" fill="none" stroke="#2563eb" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/><text x="%.1f" y="%.1f" class="axis">%s</text><text x="%.1f" y="%.1f" text-anchor="end" class="axis">%s</text>', implode(' ', $coordinates), $plotX, $y + $height - 7, $this->escape($first), $plotX + $plotWidth, $y + $height - 7, $this->escape($last));

        return implode('', $parts);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
