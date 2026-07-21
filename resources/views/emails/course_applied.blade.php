<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Course application received</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#f3f4f6; color:#111827; }
        .wrapper { width:100%; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,0.08); overflow:hidden; }
        .header { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#f9fafb; padding:20px 28px; }
        .header-title { font-size:18px; font-weight:700; letter-spacing:0.05em; text-transform:uppercase; }
        .body { padding:24px 28px 28px; }
        .paragraph { font-size:14px; line-height:1.6; margin:0 0 12px; color:#374151; }
        .highlight { font-weight:600; }
        .footer { padding:14px 28px 18px; border-top:1px solid #e5e7eb; background:#f9fafb; font-size:11px; color:#9ca3af; text-align:center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="container">
        <div class="header">
            <div class="header-title">{{ $companyName ?? ($emailBrand['companyName'] ?? config('app.name')) }}</div>
            <p style="margin-top:8px; font-size:14px;">Course application confirmation</p>
        </div>
        <div class="body">
            <p class="paragraph">Dear {{ $student->first_name ?? $student->name ?? 'Student' }},</p>
            <p class="paragraph">
                We have received your application for the course
                <span class="highlight">{{ $course->title }}</span>.
            </p>
            @isset($level)
                @if(!empty($level))
                    <p class="paragraph">
                        Selected level: <span class="highlight">{{ $level }}</span>
                    </p>
                @endif
            @endisset
            <p class="paragraph">
                Our team will review your application and payment. Once confirmed, you will receive further instructions
                on how to access the course materials and sessions.
            </p>
            <p class="paragraph">
                If you did not initiate this application, please contact our support team as soon as possible.
            </p>
            <p class="paragraph" style="margin-top:20px;">
                Best regards,<br>
                <span class="highlight">{{ $companyName ?? ($emailBrand['companyName'] ?? config('app.name')) }}</span>
            </p>
        </div>
        <div class="footer">
            <p>You are receiving this email because a course application was submitted using your email address.</p>
            <p>
                For assistance, contact
                <a href="mailto:{{ config('platform.contact_email') }}">{{ config('platform.contact_email') }}</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
