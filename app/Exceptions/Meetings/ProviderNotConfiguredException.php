<?php

namespace App\Exceptions\Meetings;

use RuntimeException;

class ProviderNotConfiguredException extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        return new self("Meeting provider \"{$provider}\" is not configured. Contact your platform administrator.");
    }
}
