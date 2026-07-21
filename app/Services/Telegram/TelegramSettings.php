<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Data\Telegram\TelegramContext;
use App\Models\TelegramNotification;
use App\Queries\LeaderboardAnalytics;
use App\Telegram\Messages\SettingsRichMessageFactory;
use Phptg\BotApi\Type\Update\Update;

class TelegramSettings
{
    public function __construct(
        private readonly TelegramContextResolver $contexts,
        private readonly TelegramNotificationSubscriptions $subscriptions,
        private readonly LeaderboardAnalytics $analytics,
        private readonly SettingsRichMessageFactory $messages,
        private readonly TelegramRichMessageGateway $gateway,
    ) {}

    public function show(Update $update): TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        $subscription = $this->callbackSubscription($update, $context)
            ?? $this->subscriptions->findOrCreate($context);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->gateway->send(
            $subscription->instance_id,
            $view->message,
            $subscription->thread_id,
            $view->keyboard,
        );

        return $subscription;
    }

    public function update(Update $update): TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        $subscription = $this->callbackSubscription($update, $context)
            ?? $this->subscriptions->findOrCreate($context);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->gateway->updateMessage(
            $view->message,
            $view->keyboard,
        );

        return $subscription;
    }

    public function enable(Update $update): TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        $subscription = $this->subscriptions->enable($context);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->gateway->send($context->instanceId, $view->message, $context->threadId, $view->keyboard);

        return $subscription;
    }

    public function toggle(Update $update): TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        $subscription = $this->callbackSubscription($update, $context);
        $subscription = $subscription === null
            ? $this->subscriptions->toggle($context)
            : $this->subscriptions->toggleSubscription($subscription);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->gateway->updateMessage(
            $view->message,
            $view->keyboard,
        );

        return $subscription;
    }

    private function callbackSubscription(
        Update $update,
        TelegramContext $context,
    ): ?TelegramNotification {
        $data = $update->callbackQuery?->data;

        if ($data === null || preg_match('/:(\d+)$/', $data, $matches) !== 1) {
            return null;
        }

        return $this->subscriptions->findForCallback((int) $matches[1], $context);
    }
}
