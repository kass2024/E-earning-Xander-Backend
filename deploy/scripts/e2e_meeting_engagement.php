#!/usr/bin/env php
<?php
/**
 * Automated meeting engagement smoke suite (run inside backend container or locally).
 *
 * Usage:
 *   php deploy/scripts/e2e_meeting_engagement.php
 *   php deploy/scripts/e2e_meeting_engagement.php --base=https://api.xanderglobalacademy.com --token=SANCTUM
 */

$base = 'http://127.0.0.1:8000';
$token = getenv('E2E_API_TOKEN') ?: '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = rtrim(substr($arg, 7), '/');
    }
    if (str_starts_with($arg, '--token=')) {
        $token = substr($arg, 8);
    }
}

function req(string $method, string $url, ?array $json = null, string $token = ''): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $json !== null ? json_encode($json) : null,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'err' => $err, 'json' => json_decode((string) $body, true)];
}

$failed = 0;
$passed = 0;
$key = 'e2e-' . bin2hex(random_bytes(4));

$checks = [
    'site_home' => ['GET', 'https://xanderglobalacademy.com/', null],
];

echo "=== Meeting engagement E2E smoke ===\n";
echo "API base: {$base}\n";
echo "Meeting key: {$key}\n\n";

// Public site must not 502
$home = req('GET', 'https://xanderglobalacademy.com/', null, '');
if ($home['code'] >= 200 && $home['code'] < 400) {
    echo "[PASS] Public site HTTP {$home['code']}\n";
    $passed++;
} else {
    echo "[FAIL] Public site HTTP {$home['code']} (502 means nginx upstream stale)\n";
    $failed++;
}

if ($token === '') {
    echo "[SKIP] Authenticated engagement API checks (set --token= or E2E_API_TOKEN)\n";
    echo "\nResult: {$passed} passed, {$failed} failed, skips present\n";
    exit($failed > 0 ? 1 : 0);
}

$steps = [
    ['POST', '/api/admin/meetings/engagement/questions', [
        'meeting_key' => $key,
        'question' => 'E2E question?',
        'author_name' => 'E2E',
        'daily_session_id' => 'e2e-sess',
    ]],
    ['GET', '/api/admin/meetings/engagement/questions?meeting_key=' . urlencode($key), null],
    ['POST', '/api/admin/meetings/engagement/polls', [
        'meeting_key' => $key,
        'question' => 'E2E poll?',
        'options' => ['A', 'B'],
        'open_now' => true,
    ]],
    ['POST', '/api/admin/meetings/engagement/stage/reorder', [
        'meeting_key' => $key,
        'members' => [
            ['daily_session_id' => 's1', 'display_name' => 'Host', 'stage_role' => 'host'],
            ['daily_session_id' => 's2', 'display_name' => 'Guest', 'stage_role' => 'panelist'],
        ],
    ]],
    ['POST', '/api/admin/meetings/engagement/breakouts', [
        'meeting_key' => $key,
        'count' => 2,
    ]],
];

foreach ($steps as [$method, $path, $payload]) {
    $res = req($method, $base . $path, $payload, $token);
    $ok = $res['code'] >= 200 && $res['code'] < 300;
    $label = "{$method} {$path}";
    if ($ok) {
        echo "[PASS] {$label} → {$res['code']}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label} → {$res['code']} {$res['body']}\n";
        $failed++;
    }
}

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
