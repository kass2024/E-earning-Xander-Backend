<?php

namespace App\Services\Meet;

use App\Models\MeetSubscription;
use App\Models\User;
use Illuminate\Support\Str;

class MeetSubscriberAccountService
{
    /**
     * Link subscription to a user, creating one when needed.
     *
     * @return array{created: bool, user_id: int, email: string, name: string, password: ?string}
     */
    public function provisionFromSubscription(
        MeetSubscription $subscription,
        ?string $email = null,
        ?string $name = null,
    ): array {
        $subscription->refresh();
        $meta = is_array($subscription->metadata) ? $subscription->metadata : [];

        if ($subscription->user_id) {
            $user = User::find($subscription->user_id);
            if ($user) {
                return [
                    'created' => false,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'password' => null,
                ];
            }
        }

        $email = strtolower(trim((string) ($email ?: ($meta['email'] ?? ''))));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required to create your account.');
        }

        $name = trim((string) ($name ?: ($meta['name'] ?? '')));
        if ($name === '') {
            $name = Str::title(str_replace(['.', '_', '-'], ' ', Str::before($email, '@')));
        }

        $existing = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($existing) {
            $subscription->update([
                'user_id' => $existing->id,
                'metadata' => array_merge($meta, [
                    'email' => $email,
                    'name' => $existing->name,
                    'linked_existing_user' => true,
                ]),
            ]);

            return [
                'created' => false,
                'user_id' => $existing->id,
                'email' => $existing->email,
                'name' => $existing->name,
                'password' => null,
            ];
        }

        $password = $this->generatePassword();

        $user = User::create([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'role' => 'meeting_user',
            'status' => 'Active',
        ]);

        $subscription->update([
            'user_id' => $user->id,
            'metadata' => array_merge($meta, [
                'email' => $email,
                'name' => $name,
                'account_created' => true,
                'issued_password' => $password,
                'account_created_at' => now()->toIso8601String(),
            ]),
        ]);

        return [
            'created' => true,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'password' => $password,
        ];
    }

    private function generatePassword(): string
    {
        return 'Meet@' . Str::upper(Str::random(4)) . random_int(1000, 9999);
    }
}
