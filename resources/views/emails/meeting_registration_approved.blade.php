<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Meeting Registration Approved</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:#0f172a;padding:22px 24px;">
                        <div style="font-size:16px;font-weight:700;color:#ffffff;line-height:1.2;">
                            {{ $appName }}
                        </div>
                        <div style="font-size:13px;color:#cbd5e1;margin-top:4px;">
                            Pathways Webinar Invitation
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px;">
                        <div style="font-size:18px;font-weight:700;color:#111827;">Meeting Registration Approved</div>
                        <div style="font-size:14px;color:#374151;margin-top:10px;line-height:1.6;">
                            Hello <strong>{{ $name }}</strong>,<br />
                            Your meeting registration has been <strong style="color:#16a34a;">approved</strong>.
                        </div>

                        <div style="margin-top:18px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">
                            <div style="font-size:14px;font-weight:700;color:#0f172a;">Session Details</div>
                            <div style="font-size:14px;color:#334155;margin-top:8px;line-height:1.6;">
                                <div style="margin-top:6px;">Platform: <strong>Zoom (online)</strong></div>
                                @if(!empty($nextSession))
                                    <div style="margin-top:8px;">Scheduled Session: <strong>{{ $nextSession }}</strong></div>
                                @else
                                    <div style="margin-top:8px;">Scheduled Session: <strong>To be confirmed</strong></div>
                                @endif
                            </div>
                        </div>

                        @if(!empty($scheduleDescription))
                            <div style="margin-top:18px;display:flex;flex-direction:column;gap:12px;">
                                <div style="padding:14px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#fdf2e9;">
                                    <div style="font-size:13px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:0.08em;">Meeting Description</div>
                                    <div style="font-size:14px;color:#7c2d12;margin-top:6px;line-height:1.6;">
                                        {{ $scheduleDescription }}
                                    </div>
                                </div>
                            </div>
                        @endif

                        @php
                            $effectiveJoinUrl = !empty($joinUrl)
                                ? $joinUrl
                                : 'https://us06web.zoom.us/j/84024505834?pwd=S35BVbbF5OO8zY1zBMIw59YKw3L5Gx.1';
                        @endphp

                        <div style="margin-top:18px;padding:14px 16px;border:1px solid #dbeafe;border-radius:10px;background:#eff6ff;">
                            <div style="font-size:14px;font-weight:700;color:#1d4ed8;">Zoom Join Link</div>
                            <div style="margin-top:12px;">
                                <a href="{{ $effectiveJoinUrl }}" target="_blank" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 16px;border-radius:10px;">Join Webinar</a>
                            </div>
                            <div style="font-size:13px;color:#1f2937;margin-top:10px;word-break:break-all;line-height:1.6;">
                                If the button doesn't work, copy and paste this link into your browser:<br />
                                <a href="{{ $effectiveJoinUrl }}" target="_blank" style="color:#1d4ed8;text-decoration:underline;">{{ $effectiveJoinUrl }}</a>
                            </div>
                        </div>

                        <div style="font-size:13px;color:#6b7280;margin-top:18px;line-height:1.6;">
                            If you have any questions, reply to this email and our team will assist you.
                        </div>

                        <div style="margin-top:22px;border-top:1px solid #e5e7eb;padding-top:14px;font-size:13px;color:#6b7280;line-height:1.6;">
                            Thank you,<br />
                            <strong>{{ $appName }}</strong>
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc;padding:14px 24px;font-size:12px;color:#94a3b8;line-height:1.6;">
                        This is an automated message. Please do not share your private information by email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
