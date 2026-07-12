<?php

namespace App\Enums;

enum MeetingProvider: string
{
    case Zoom = 'zoom';
    case Daily = 'daily';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function tryFromString(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        return self::tryFrom($value);
    }

    public static function fromStringOrDefault(?string $value, self $default = self::Daily): self
    {
        return self::tryFromString($value) ?? $default;
    }
}
