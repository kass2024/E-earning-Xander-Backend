<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }} - Class Scheduled</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #111827; }
        .wrapper { width: 100%; padding: 24px 0; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); overflow: hidden; }
        .header { background: linear-gradient(135deg, #0f172a, #1d4ed8); color: #f9fafb; padding: 20px 28px; }
        .header-title { font-size: 18px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
        .header-subtitle { margin-top: 8px; font-size: 14px; color: #e5e7eb; }
        .body { padding: 24px 28px 28px; }
        .greeting { font-size: 16px; margin-bottom: 16px; }
        .paragraph { font-size: 14px; line-height: 1.6; margin: 0 0 12px; color: #374151; }
        .info-box { background-color: #eff6ff; border-radius: 10px; padding: 14px 16px; border: 1px solid #bfdbfe; margin: 18px 0; font-size: 13px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 4px; gap: 12px; }
        .info-label { color: #6b7280; }
        .info-value { font-weight: 600; color: #111827; text-align: right; }
        .footer { padding: 14px 28px 18px; border-top: 1px solid #e5e7eb; background-color: #f9fafb; font-size: 11px; color: #9ca3af; text-align: center; }
        a.btn { display: inline-block; padding: 10px 18px; background-color: #1d4ed8; color: #f9fafb !important; text-decoration: none; border-radius: 999px; font-size: 13px; font-weight: 600; }
        a.link { color: #1d4ed8; word-break: break-all; }
    </style>
</head>
<body>
<div class="wrapper">
  <div class="container">
    <div class="header">
      <div class="header-title">{{ config('app.name') }}</div>
      <div class="header-subtitle">New class scheduled</div>
    </div>
    <div class="body">
      <p class="greeting">Dear {{ $staff->name ?? 'Instructor' }},</p>
      <p class="paragraph">
        A new class has been scheduled for your course
        <strong>{{ $course->title ?? 'your course' }}</strong>.
      </p>
      <div class="info-box">
        <div class="info-row">
          <span class="info-label">Date &amp; time</span>
          <span class="info-value">{{ \Carbon\Carbon::parse($startTime)->toDayDateTimeString() }}</span>
        </div>
      </div>
      @if(!empty($notes))
        <p class="paragraph"><strong>Notes:</strong> {{ $notes }}</p>
      @endif
      <p class="paragraph">
        Host this session in your browser — audio, video, screen share, chat, and participants are built in.
      </p>
      @if(!empty($hostLink))
        <p class="paragraph">
          <a href="{{ $hostLink }}" class="btn">Start in app (host studio)</a>
        </p>
        <p class="paragraph">
          Host link:<br>
          <a href="{{ $hostLink }}" class="link">{{ $hostLink }}</a>
        </p>
      @endif
      @if(!empty($dashboardLink))
        <p class="paragraph">
          Manage sessions from your dashboard:<br>
          <a href="{{ $dashboardLink }}" class="link">{{ $dashboardLink }}</a>
        </p>
      @endif
      <p class="paragraph" style="margin-top: 20px;">
        Best regards,<br>
        <strong>{{ config('app.name') }}</strong>
      </p>
    </div>
    <div class="footer">
      You are receiving this email because a class was scheduled for a course where you are the instructor or staff contact.
    </div>
  </div>
</div>
</body>
</html>
