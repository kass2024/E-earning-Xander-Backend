<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $success ? 'Appointment cancelled' : 'Link not found' }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="min-height:100vh;background:#f6f7fb;">
    <tr>
        <td align="center" style="padding:40px 16px;">
            <table role="presentation" width="520" cellspacing="0" cellpadding="0" style="width:520px;max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:{{ $success ? '#012F6B' : '#7f1d1d' }};padding:24px;">
                        <div style="font-size:18px;font-weight:700;color:#ffffff;">{{ $appName }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 28px;text-align:center;">
                        @if($success)
                            <div style="font-size:22px;font-weight:700;color:#111827;">Your appointment is cancelled</div>
                            <div style="font-size:14px;color:#374151;margin-top:12px;line-height:1.7;">
                                @if(!empty($name))
                                    Hello <strong>{{ $name }}</strong>,<br />
                                @endif
                                We have cancelled your appointment as requested. We are sorry we could not meet this time and hope to see you soon.
                            </div>
                            @if(!empty($rebookUrl))
                                <div style="margin-top:26px;">
                                    <a href="{{ $rebookUrl }}"
                                       style="display:inline-block;background:#012F6B;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 26px;border-radius:9999px;">
                                        Book a new meeting
                                    </a>
                                </div>
                            @endif
                        @else
                            <div style="font-size:22px;font-weight:700;color:#111827;">This link is no longer valid</div>
                            <div style="font-size:14px;color:#374151;margin-top:12px;line-height:1.7;">
                                We could not find an appointment for this cancellation link. It may have already been cancelled or the link is incorrect.
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
