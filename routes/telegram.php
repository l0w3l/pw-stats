<?php

declare(strict_types=1);

use App\Telegram\Handlers\NotificationFrequencyCallbackHandler;
use App\Telegram\Handlers\NotificationLocaleCallbackHandler;
use App\Telegram\Handlers\NotificationRefreshCallbackHandler;
use App\Telegram\Handlers\NotificationSwitchCommandHandler;
use App\Telegram\Handlers\NotificationToggleCallbackHandler;
use App\Telegram\Handlers\StartCommandHandler;
use App\Telegram\Messages\SettingsRichMessageFactory;
use Lowel\Telepath\Facades\Telepath;
use Lowel\Telepath\Http\Middlewares\Buttons\AnswerCallbackQueryMiddleware;

Telepath::onCommand([StartCommandHandler::class, 'handle'], '^start(?:@\\w+)?(?:\\s.*)?$');
Telepath::onCommand([NotificationSwitchCommandHandler::class, 'handle'], '^notification_switch(?:@\\w+)?(?:\\s.*)?$');

// Telegram emits channel commands as channel_post rather than message updates.
Telepath::onChannelPost([StartCommandHandler::class, 'handle'], '^start(?:@\\w+)?(?:\\s.*)?$');
Telepath::onChannelPost([NotificationSwitchCommandHandler::class, 'handle'], '^notification_switch(?:@\\w+)?(?:\\s.*)?$');

Telepath::onCallbackQuery(
    [NotificationToggleCallbackHandler::class, 'handle'],
    '^'.preg_quote(SettingsRichMessageFactory::CALLBACK_TOGGLE, '/').'\d+$'
)->middleware(AnswerCallbackQueryMiddleware::class);

Telepath::onCallbackQuery(
    [NotificationFrequencyCallbackHandler::class, 'handle'],
    '^'.preg_quote(SettingsRichMessageFactory::CALLBACK_FREQUENCY, '/').'(?:day|week|month):\d+$'
)->middleware(AnswerCallbackQueryMiddleware::class);

Telepath::onCallbackQuery(
    [NotificationRefreshCallbackHandler::class, 'handle'],
    '^'.preg_quote(SettingsRichMessageFactory::CALLBACK_REFRESH, '/').'\d+$'
)->middleware(AnswerCallbackQueryMiddleware::class);

Telepath::onCallbackQuery(
    [NotificationLocaleCallbackHandler::class, 'handle'],
    '^'.preg_quote(SettingsRichMessageFactory::CALLBACK_LOCALE, '/').'(?:ru|en):\d+$'
)->middleware(AnswerCallbackQueryMiddleware::class);
