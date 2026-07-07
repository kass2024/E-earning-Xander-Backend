<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Booking confirmed</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:560px;max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(1,47,107,0.08);">
                <tr>
                    <td style="padding:32px 32px 20px;text-align:center;">
                        <div style="width:56px;height:56px;margin:0 auto 16px;border-radius:50%;background:#012F6B;display:flex;align-items:center;justify-content:center;">
                            <span style="color:#ffffff;font-size:28px;line-height:56px;">&#10003;</span>
                        </div>
                        <div style="font-size:24px;font-weight:700;color:#012F6B;">Booking confirmed</div>
                        @if(!empty($recipientEmail))
                            <div style="font-size:14px;color:#64748b;margin-top:10px;line-height:1.6;">
                                Email sent to <strong style="color:#012F6B;">{{ $recipientEmail }}</strong>
                            </div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 32px 24px;">
                        <div style="font-size:14px;color:#334155;line-height:1.6;">
                            Hello <strong>{{ $name }}</strong>,<br />
                            Your session with <strong>{{ $appName }}</strong> is confirmed. Save the details below and use the button to join when it is time.
                        </div>

                        <div style="margin-top:20px;padding:16px 18px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    @if(!empty($nextSession))
                                        <td style="width:64px;vertical-align:top;padding-right:14px;">
                                            <div style="width:56px;border-radius:10px;border:1px solid #cbd5e1;background:#ffffff;text-align:center;overflow:hidden;">
                                                <div style="background:#012F6B;color:#ffffff;font-size:11px;font-weight:700;padding:4px 0;text-transform:uppercase;">
                                                    Session
                                                </div>
                                                <div style="font-size:18px;font-weight:700;color:#012F6B;padding:8px 4px;line-height:1.2;">
                                                    &#10003;
                                                </div>
                                            </div>
                                        </td>
                                    @endif
                                    <td style="vertical-align:top;">
                                        <div style="font-size:16px;font-weight:700;color:#012F6B;">Online consultation</div>
                                        @if(!empty($nextSession))
                                            <div style="font-size:14px;color:#334155;margin-top:8px;line-height:1.6;">
                                                <strong>{{ $nextSession }}</strong>
                                            </div>
                                        @else
                                            <div style="font-size:14px;color:#334155;margin-top:8px;">Time to be confirmed</div>
                                        @endif
                                        <div style="font-size:13px;color:#64748b;margin-top:8px;">Platform: Zoom (online) via {{ $appName }}</div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        @if(!empty($scheduleDescription))
                            <div style="margin-top:16px;padding:14px 16px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;">
                                <div style="font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.06em;">About this session</div>
                                <div style="font-size:14px;color:#78350f;margin-top:6px;line-height:1.6;">{{ $scheduleDescription }}</div>
                            </div>
                        @endif

                        @if(!empty($joinUrl))
                            <div style="margin-top:20px;padding:16px 18px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">
                                <div style="font-size:14px;font-weight:700;color:#012F6B;">Join online meeting</div>
                                <div style="font-size:13px;color:#475569;margin-top:6px;line-height:1.6;">
                                    Open the link below in your browser to join through our secure meeting room (no Google Meet required).
                                </div>
                                <div style="margin-top:14px;">
                                    <a href="{{ $joinUrl }}" target="_blank" style="display:inline-block;background:#012F6B;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 20px;border-radius:999px;">Join meeting</a>
                                </div>
                                <div style="font-size:12px;color:#475569;margin-top:12px;word-break:break-all;line-height:1.6;">
                                    Or copy this link:<br />
                                    <a href="{{ $joinUrl }}" target="_blank" style="color:#012F6B;text-decoration:underline;">{{ $joinUrl }}</a>
                                </div>
                            </div>
                        @endif

                        <div style="font-size:13px;color:#64748b;margin-top:20px;line-height:1.6;">
                            Need to make a change? Reply to this email and our team will help.
                        </div>

                        <div style="margin-top:22px;border-top:1px solid #e2e8f0;padding-top:14px;font-size:13px;color:#64748b;line-height:1.6;">
                            Thank you,<br />
                            <strong style="color:#012F6B;">{{ $appName }}</strong>
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc;padding:14px 32px;font-size:12px;color:#94a3b8;line-height:1.6;">
                        This is an automated confirmation. Please do not share your private meeting link publicly.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
