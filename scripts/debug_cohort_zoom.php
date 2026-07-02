<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cohortId = (int) ($argv[1] ?? 15);
$c = App\Models\LiveZoomCohort::find($cohortId);
if (!$c) {
    echo "Cohort {$cohortId} not found\n";
    exit(1);
}

$zoom = app(App\Services\ZoomService::class);
$mid = preg_replace('/\D+/', '', (string) ($c->zoom_meeting_id ?? '')) ?: '';

echo "cohort_id={$c->id}\n";
echo "session_status={$c->session_status}\n";
echo "zoom_meeting_id={$c->zoom_meeting_id}\n";
echo "zoom_password=" . ($c->zoom_password ?? '(empty)') . "\n";
echo "zoom_link=" . ($c->zoom_link ?? '(empty)') . "\n";
echo "host_user_id=[" . config('services.zoom.host_user_id') . "]\n";
echo "embed_configured=" . (app(App\Services\ZoomMeetingSdkService::class)->isConfigured() ? 'yes' : 'no') . "\n";

if ($mid === '') {
    echo "No meeting id\n";
    exit(0);
}

$m = $zoom->getMeeting($mid);
echo "getMeeting=" . (empty($m['error']) ? 'ok' : 'fail status=' . ($m['status'] ?? '?')) . "\n";
if (!empty($m['error'])) {
    echo "getMeeting_body=" . json_encode($m['body'] ?? null) . "\n";
} else {
    echo "api_type={$m['type']}\n";
    echo "api_password=" . ($m['password'] ?? '(none)') . "\n";
    echo "encrypted_password=" . (isset($m['encrypted_password']) ? substr((string) $m['encrypted_password'], 0, 20) . '…' : '(none)') . "\n";
    echo "join_before_host=" . json_encode($m['settings']['join_before_host'] ?? null) . "\n";
    echo "waiting_room=" . json_encode($m['settings']['waiting_room'] ?? null) . "\n";
}

echo "live_meetings_api=";
$liveResp = $zoom->client();
if ($liveResp) {
    $host = $zoom->resolveHostUserId();
    $r = $liveResp->get('/users/' . rawurlencode($host) . '/meetings', ['type' => 'live', 'page_size' => 5]);
    echo $r->status() . ' ' . $r->body() . "\n";
}

$resolved = $zoom->resolveMeetingPassword($c, is_array($m) && empty($m['error']) ? $m : null);
echo "resolved_password=" . ($resolved !== '' ? $resolved : '(empty)') . "\n";

$payload = app(App\Services\ZoomMeetingSdkService::class)->buildJoinPayload($mid, 'Test Joiner', 0, $resolved);
echo "participant_sig_len=" . strlen($payload['signature']) . "\n";
echo "participant_meeting_number={$payload['meeting_number']}\n";

$hostPayload = app(App\Services\ZoomMeetingSdkService::class)->buildJoinPayload($mid, 'Test Host', 1, $resolved);
echo "host_sig_len=" . strlen($hostPayload['signature']) . "\n";
