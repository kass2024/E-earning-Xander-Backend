<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $companyName ?? ($emailBrand['companyName'] ?? config('app.name')) }} - Course Application Update</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #111827; }
        .wrapper { width: 100%; padding: 24px 0; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); overflow: hidden; }
        .header { background: linear-gradient(135deg, #b91c1c, #ef4444); color: #f9fafb; padding: 20px 28px; }
        .header-title { font-size: 18px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
        .header-subtitle { margin-top: 8px; font-size: 14px; color: #fee2e2; }
        .body { padding: 24px 28px 28px; }
        .greeting { font-size: 16px; margin-bottom: 16px; }
        .paragraph { font-size: 14px; line-height: 1.6; margin: 0 0 12px; color: #374151; }
        .reason-box { background-color: #fef2f2; border-radius: 10px; padding: 14px 16px; border: 1px solid #fecaca; margin: 18px 0; font-size: 13px; }
        .footer { padding: 14px 28px 18px; border-top: 1px solid #e5e7eb; background-color: #f9fafb; font-size: 11px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
  <div class="container">
    <div class="header">
      <div class="header-title">{{ $companyName ?? ($emailBrand['companyName'] ?? config('app.name')) }}</div>
      <div class="header-subtitle">Course application update</div>
    </div>
    <div class="body">
      <p class="greeting">Dear {{ $student->first_name ?? 'Student' }},</p>
      <p class="paragraph">
        Thank you for your interest in the course <strong>{{ $course->title ?? 'your selected course' }}</strong>.
        After reviewing your application, we are not able to approve this particular course enrollment at this time.
      </p>
      @if(!empty($reason))
        <div class="reason-box">
          <strong>Reason provided by our team:</strong>
          <p class="paragraph" style="margin-top: 6px; margin-bottom: 0;">{{ $reason }}</p>
        </div>
      @endif
      <p class="paragraph">
        You are welcome to explore other courses and programs within {{ $companyName ?? ($emailBrand['companyName'] ?? config('app.name')) }} that
        may better fit your current profile or goals.
      </p>
      <p class="paragraph" style="margin-top: 20px;">
        Best regards,<br>
        <strong>{{ $companyName ?? ($emailBrand['companyName'] ?? config('app.name')) }}</strong>
      </p>
    </div>
    <div class="footer">
      You are receiving this email because a course application on your learner account was reviewed.
    </div>
  </div>
</div>
</body>
</html>
