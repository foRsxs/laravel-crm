<?php

namespace Webkul\GreenApi\Services;

use InvalidArgumentException;

class ChatId
{
    public static function fromPhone(string $phone): string
    {
        // Never guess a country or silently rewrite a local prefix.
        $number = preg_replace('/[\\s()+.\\-]/u', '', trim($phone));

        if (! preg_match('/^[1-9][0-9]{6,14}$/D', $number ?? '')) {
            throw new InvalidArgumentException('An international phone number is required.');
        }

        return $number.'@c.us';
    }

    public static function isPersonal(string $chatId): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{6,14}@c\\.us$/D', $chatId);
    }
}
