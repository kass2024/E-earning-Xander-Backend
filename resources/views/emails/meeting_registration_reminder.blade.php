<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Meeting reminder</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:560px;max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="background:#012F6B;padding:22px 28px;">
                        <div style="font-size:16px;font-weight:700;color:#ffffff;">{{ $appName }}</div>
                        <div style="font-size:13px;color:#cbd5e1;margin-top:4px;">Upcoming session reminder</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;">
                        <div style="font-size:18px;font-weight:700;color:#012F6B;">Your session starts soon</div>
                        <div style="font-size:14px;color:#334155;margin-top:10px;line-height:1.6;">
                            Hello <strong>{{ $name }}</strong>,<br />
                            This is a friendly reminder about your upcoming online session.
                        </div>

                        @if(!empty($customMessage))
                            <div style="margin-top:18px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                                <div style="font-size:13px;font-weight:700;color:#012F6B;">Message</div>
                                <div style="font-size:14px;color:#334155;margin-top:6px;line-height:1.6;">{{ $customMessage }}</div>
                            </div>
                        @endif

                        <div style="margin-top:18px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                            <div style="font-size:13px;font-weight:700;color:#012F6B;">Session details</div>
                            <div style="font-size:14px;color:#334155;margin-top:8px;line-height:1.6;">
                                <div>Platform: <strong>Zoom (online)</strong></div>
                                @if(!empty($nextSession))
                                    <div style="margin-top:8px;">When: <strong>{{ $nextSession }}</strong></div>
                                @endif
                            </div>
                        </div>

                        @if(!empty($joinUrl))
                            <div style="margin-top:18px;padding:14px 16px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">
                                <div style="font-size:14px;font-weight:700;color:#012F6B;">Join online meeting</div>
                                <div style="margin-top:12px;">
                                    <a href="{{ $joinUrl }}" target="_blank" style="display:inline-block;background:#012F6B;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 20px;border-radius:999px;">Join meeting</a>
                                </div>
                                <div style="font-size:12px;color:#475569;margin-top:10px;word-break:break-all;line-height:1.6;">
                                    <a href="{{ $joinUrl }}" target="_blank" style="color:#012F6B;text-decoration:underline;">{{ $joinUrl }}</a>
                                </div>
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
