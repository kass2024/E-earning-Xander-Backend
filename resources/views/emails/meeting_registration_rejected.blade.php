<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Meeting Registration Rejected</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:#7f1d1d;padding:22px 24px;">
                        <div style="font-size:16px;font-weight:700;color:#ffffff;line-height:1.2;">
                            {{ $appName }}
                        </div>
                        <div style="font-size:13px;color:#fecaca;margin-top:4px;">
                            Meeting Registration Update
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px;">
                        <div style="font-size:18px;font-weight:700;color:#111827;">Meeting Registration Rejected</div>
                        <div style="font-size:14px;color:#374151;margin-top:10px;line-height:1.6;">
                            Hello <strong>{{ $name }}</strong>,<br />
                            Unfortunately, your meeting registration has been <strong style="color:#dc2626;">rejected</strong>.
                        </div>

                        @if(!empty($reason))
                            <div style="margin-top:18px;padding:14px 16px;border:1px solid #fee2e2;border-radius:10px;background:#fef2f2;">
                                <div style="font-size:14px;font-weight:700;color:#b91c1c;">Reason</div>
                                <div style="font-size:14px;color:#1f2937;margin-top:8px;line-height:1.6;">
                                    {{ $reason }}
                                </div>
                            </div>
                        @endif

                        <div style="font-size:13px;color:#6b7280;margin-top:18px;line-height:1.6;">
                            If you believe this is a mistake or you need help, please reply to this email.
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
