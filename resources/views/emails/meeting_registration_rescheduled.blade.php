<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reschedule your appointment</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:#012F6B;padding:22px 24px;">
                        <div style="font-size:16px;font-weight:700;color:#ffffff;line-height:1.2;">
                            {{ $appName }}
                        </div>
                        <div style="font-size:13px;color:#cbd5e1;margin-top:4px;">
                            Appointment Reschedule Notice
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px;">
                        <div style="font-size:18px;font-weight:700;color:#111827;">Dear Valued Customer,</div>
                        <div style="font-size:14px;color:#374151;margin-top:10px;line-height:1.7;">
                            @if(!empty($name))
                                Hello <strong>{{ $name }}</strong>,<br />
                            @endif
                            {{ $apologyMessage }}
                        </div>

                        @if(!empty($proposedTime))
                            <div style="margin-top:18px;padding:14px 16px;border:1px solid #dbeafe;border-radius:10px;background:#eff6ff;">
                                <div style="font-size:13px;font-weight:700;color:#012F6B;">Proposed new time</div>
                                <div style="font-size:14px;color:#1f2937;margin-top:6px;line-height:1.6;">
                                    {{ $proposedTime }}
                                </div>
                                <div style="font-size:12px;color:#6b7280;margin-top:6px;line-height:1.6;">
                                    If this time does not work for you, please pick another slot below.
                                </div>
                            </div>
                        @endif

                        <div style="margin-top:26px;">
                            <table role="presentation" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="padding-right:10px;">
                                        <a href="{{ $rebookUrl }}"
                                           style="display:inline-block;background:#012F6B;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:9999px;">
                                            Book another appointment
                                        </a>
                                    </td>
                                    @if(!empty($cancelUrl))
                                        <td>
                                            <a href="{{ $cancelUrl }}"
                                               style="display:inline-block;background:#ffffff;color:#012F6B;text-decoration:none;font-weight:700;font-size:14px;padding:11px 22px;border-radius:9999px;border:1px solid #012F6B;">
                                                Cancel appointment
                                            </a>
                                        </td>
                                    @endif
                                </tr>
                            </table>
                        </div>

                        <div style="font-size:13px;color:#6b7280;margin-top:22px;line-height:1.6;">
                            If you have any questions, simply reply to this email and our team will be happy to help.
                        </div>

                        <div style="margin-top:22px;border-top:1px solid #e5e7eb;padding-top:14px;font-size:13px;color:#6b7280;line-height:1.6;">
                            Thank you for your patience and understanding,<br />
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
