<?php

namespace App\Console\Commands;

use App\Models\AdminZoomMeeting;
use App\Services\MailDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SendAdminZoomMeetingReminders extends Command
{
    protected $signature = 'admin-zoom-meetings:send-reminders';

    protected $description = 'Send reminder emails for admin-scheduled Zoom meetings';

    public function handle(MailDeliveryService $mail): int
    {
        if (!Schema::hasTable('admin_zoom_meetings')) {
            return self::SUCCESS;
        }

        $now = now();

        AdminZoomMeeting::query()
            ->with('createdBy')
            ->whereNotNull('start_time')
            ->where('start_time', '>', $now)
            ->orderBy('id')
            ->chunkById(50, function ($meetings) use ($mail, $now) {
                foreach ($meetings as $meeting) {
                    $this->sendDueReminder($meeting, $mail, $now);
                }
            });

        return self::SUCCESS;
    }

    protected function sendDueReminder(AdminZoomMeeting $meeting, MailDeliveryService $mail, \Carbon\Carbon $now): void
    {
        $meta = is_array($meeting->meta) ? $meeting->meta : [];
        $reminder = strtolower(trim((string) ($meta['reminder'] ?? 'none')));
        if ($reminder === '' || $reminder === 'none' || !empty($meta['reminder_sent_at'])) {
            return;
        }

        $minutesBefore = match ($reminder) {
            '10m' => 10,
            '1h' => 60,
            '24h' => 24 * 60,
            default => null,
        };

        if ($minutesBefore === null || !$meeting->start_time) {
            return;
        }

        $remindAt = $meeting->start_time->copy()->subMinutes($minutesBefore);
        if ($now->lt($remindAt) || $now->gte($meeting->start_time)) {
            return;
        }

        $emails = $this->recipientEmails($meeting, $meta);
        if ($emails === []) {
            return;
        }

        $subject = 'Reminder: ' . ($meeting->topic ?: 'Zoom meeting');
        $lines = [
            $subject,
            'Starts: ' . $meeting->start_time->toDayDateTimeString(),
        ];

        if (!empty($meeting->join_url)) {
            $lines[] = 'Join link: ' . $meeting->join_url;
        }

        if (!empty($meeting->password)) {
            $lines[] = 'Password: ' . $meeting->password;
        }

        $body = implode("\n", $lines);

        foreach ($emails as $email) {
            try {
                $mail->sendRaw($body, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }, [
                    'event' => 'admin_zoom_meeting_reminder',
                    'email' => $email,
                    'meeting_id' => $meeting->zoom_meeting_id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Admin Zoom meeting reminder failed', [
                    'meeting_id' => $meeting->zoom_meeting_id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $meta['reminder_sent_at'] = now()->toIso8601String();
        $meeting->meta = $meta;
        $meeting->save();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    protected function recipientEmails(AdminZoomMeeting $meeting, array $meta): array
    {
        $emails = [];

        if (!empty($meta['invite_emails']) && is_string($meta['invite_emails'])) {
            foreach (explode(',', $meta['invite_emails']) as $email) {
                $email = trim($email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        }

        $hostEmail = $meeting->createdBy?->email;
        if (is_string($hostEmail) && $hostEmail !== '' && filter_var($hostEmail, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $hostEmail;
        }

        return array_values(array_unique($emails));
    }
}
