<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use Illuminate\Contracts\Translation\Translator;

final readonly class TelegramTranslations
{
    private const DEFAULT_LOCALE = 'ru';

    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['ru', 'en'];

    public function __construct(private Translator $translator) {}

    public function locale(?string $locale): string
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true)
            ? $locale
            : self::DEFAULT_LOCALE;
    }

    /** @param array<string, scalar> $replace */
    public function get(string $key, ?string $locale, array $replace = []): string
    {
        $value = $this->translator->get($key, $replace, $this->locale($locale));

        return is_string($value) ? $value : $key;
    }
}
