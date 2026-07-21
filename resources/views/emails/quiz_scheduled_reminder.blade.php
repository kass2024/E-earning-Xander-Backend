<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $kindLabel }} reminder</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $brandName = $companyName ?? ($emailBrand['companyName'] ?? config('app.name'));
        $primary = $brandPrimary ?? ($emailBrand['primaryColor'] ?? '#254D81');
        $headerFrom = $brandHeaderFrom ?? ($emailBrand['headerFrom'] ?? '#012F6B');
        $headerTo = $brandHeaderTo ?? ($emailBrand['headerTo'] ?? $primary);
    @endphp
    <style>
        body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#f3f4f6; color:#111827; }
        .wrapper { width:100%; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,0.08); overflow:hidden; }
        .header { background: linear-gradient(135deg, {{ $headerFrom }}, {{ $headerTo }}); color:#f9fafb; padding:20px 28px; }
        .header-title { font-size:18px; font-weight:700; letter-spacing:0.05em; text-transform:uppercase; }
        .body { padding:24px 28px 28px; }
        .paragraph { font-size:14px; line-height:1.6; margin:0 0 12px; color:#374151; }
        .highlight { font-weight:600; }
        .btn { display:inline-block; margin-top:12px; padding:10px 18px; background:{{ $primary }}; color:#ffffff !important; text-decoration:none; border-radius:999px; font-size:13px; font-weight:600; }
        .footer { padding:14px 28px 18px; border-top:1px solid #e5e7eb; background:#f9fafb; font-size:11px; color:#9ca3af; text-align:center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="container">
        <div class="header">
            <div class="header-title">{{ $brandName }}</div>
            <p style="margin-top:8px;font-size:14px;">{{ $kindLabel }} starts in 1 hour 30 minutes</p>
        </div>
        <div class="body">
            <p class="paragraph">Dear {{ $student->first_name ?? $student->name ?? 'Student' }},</p>
            <p class="paragraph">
                Your scheduled <span class="highlight">{{ strtolower($kindLabel) }}</span> for
                <span class="highlight">{{ $course->title ?? 'your course' }}</span> —
                <span class="highlight">{{ $quiz->title ?? 'Assessment' }}</span> —
                opens at <span class="highlight">{{ $opensAtLabel }}</span>.
            </p>
            @if(!empty($timeLimit))
                <p class="paragraph"><span class="highlight">Time limit:</span> {{ $timeLimit }} minutes once you start</p>
            @endif
            <p class="paragraph"><span class="highlight">Pass mark:</span> {{ $passingScore }}%</p>
            <p class="paragraph">You will be able to start the assessment at the scheduled time from your learner dashboard.</p>
            @if(!empty($takeUrl))
                <a class="btn" href="{{ $takeUrl }}">Open learner dashboard</a>
            @endif
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ $brandName }}. All rights reserved.
        </div>
    </div>
</div>
</body>
</html>
