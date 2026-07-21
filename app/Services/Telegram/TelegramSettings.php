<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Data\Telegram\TelegramContext;
use App\Models\TelegramNotification;
use App\Queries\LeaderboardAnalytics;
use App\Telegram\Messages\SettingsRichMessageFactory;
use App\Telegram\Messages\SettingsView;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;

class TelegramSettings
{
    public function __construct(
        private readonly TelegramContextResolver $contexts,
        private readonly TelegramNotificationSubscriptions $subscriptions,
        private readonly LeaderboardAnalytics $analytics,
        private readonly SettingsRichMessageFactory $messages,
        private readonly TelegramRichMessageGateway $gateway,
        private readonly TelegramSettingsAuthorization $authorization,
        private readonly TelegramInboundRateLimiter $inboundRateLimiter,
    ) {}

    public function show(Update $update): ?TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        if (! $this->mayProceed($update, $context)) {
            return null;
        }

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

    public function update(Update $update): ?TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        if (! $this->mayProceed($update, $context)) {
            return null;
        }

        $subscription = $this->callbackSubscription($update, $context)
            ?? $this->subscriptions->findOrCreate($context);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->updateOrSendReplacement($subscription, $view);

        return $subscription;
    }

    public function enable(Update $update): ?TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        if (! $this->mayProceed($update, $context)) {
            return null;
        }

        $subscription = $this->subscriptions->enable($context);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->gateway->send($context->instanceId, $view->message, $context->threadId, $view->keyboard);

        return $subscription;
    }

    public function toggle(Update $update): ?TelegramNotification
    {
        $context = $this->contexts->resolve($update);
        if (! $this->mayProceed($update, $context)) {
            return null;
        }

        $subscription = $this->callbackSubscription($update, $context);
        $subscription = $subscription === null
            ? $this->subscriptions->toggle($context)
            : $this->subscriptions->toggleSubscription($subscription);
        $view = $this->messages->make($this->analytics->get(), $subscription);
        $this->updateOrSendReplacement($subscription, $view);

        return $subscription;
    }

    private function updateOrSendReplacement(TelegramNotification $subscription, SettingsView $view): void
    {
        $result = $this->gateway->updateMessage($view->message, $view->keyboard);

        if ($result === true || $result instanceof Message || $this->isMessageNotModified($result)) {
            return;
        }

        // This is intentionally a direct send rather than another settings
        // operation: authorization and mutation have already happened once.
        // The gateway applies the same distributed outbound limiter as every
        // other send, and a failed replacement is never retried recursively.
        $this->gateway->send(
            $subscription->instance_id,
            $view->message,
            $subscription->thread_id,
            $view->keyboard,
        );
    }

    private function isMessageNotModified(FailResult $result): bool
    {
        return $result->errorCode === 400
            && str_contains(strtolower($result->description ?? ''), 'message is not modified');
    }

    private function mayProceed(Update $update, TelegramContext $context): bool
    {
        $actorId = $this->authorization->actorId($update, $context);

        return $actorId !== null
            && $this->inboundRateLimiter->allows($actorId, $context->instanceId)
            && $this->authorization->allows($update, $context, $actorId);
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
