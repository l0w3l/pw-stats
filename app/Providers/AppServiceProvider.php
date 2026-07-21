<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Telegram\Sleeper;
use App\Contracts\Telegram\TelegramChatMemberGateway;
use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Services\PixelWorld\Charts\ChartRenderer;
use App\Services\PixelWorld\Charts\ImagickSvgChartRenderer;
use App\Services\PixelWorld\Leaderboard\LeaderboardClient;
use App\Services\PixelWorld\Leaderboard\PixelWorldLeaderboardClient;
use App\Services\Telegram\BotApiTelegramChatMemberGateway;
use App\Services\Telegram\NativeSleeper;
use App\Services\Telegram\SpiritBoxRichMessageGateway;
use App\Services\Telegram\TelegramWebAppDataClient;
use App\Services\Telegram\TelegramWebAppDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TelegramWebAppDataProvider::class, TelegramWebAppDataClient::class);
        $this->app->bind(LeaderboardClient::class, PixelWorldLeaderboardClient::class);
        $this->app->bind(ChartRenderer::class, ImagickSvgChartRenderer::class);
        $this->app->bind(TelegramRichMessageGateway::class, SpiritBoxRichMessageGateway::class);
        $this->app->bind(TelegramChatMemberGateway::class, BotApiTelegramChatMemberGateway::class);
        $this->app->bind(Sleeper::class, NativeSleeper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
