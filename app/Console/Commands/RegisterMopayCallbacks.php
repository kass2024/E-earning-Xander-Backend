<?php

namespace App\Console\Commands;

use App\Services\MopayPaymentService;
use Illuminate\Console\Command;

class RegisterMopayCallbacks extends Command
{
    protected $signature = 'mopay:register-callbacks {--url=}';

    protected $description = 'Register MoPay callback_url and callback_signing_key via /api/v1/user/settings';

    public function handle(MopayPaymentService $mopay): int
    {
        $result = $mopay->registerCallbackSettings($this->option('url') ?: null);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return !empty($result['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
