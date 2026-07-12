<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Appointment reminder</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#202124;">

@php
    $meetBrand = $meetBrand ?? 'XanderTech meet';
    $displayJoin = $joinUrlDisplay ?? $joinUrl ?? null;
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:560px;max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #dadce0;">
                <tr>
                    <td style="padding:28px 28px 8px;">
                        @if(!empty($joinUrl))
                            <a href="{{ $joinUrl }}" target="_blank"
                               style="display:inline-block;background:#1a73e8;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 22px;border-radius:4px;">
                                Join with {{ $meetBrand }}
                            </a>
                            <div style="margin-top:16px;font-size:14px;color:#3c4043;line-height:1.6;">
                                <span style="color:#5f6368;">Meeting link</span><br />
                                <a href="{{ $joinUrl }}" target="_blank" style="color:#1a73e8;text-decoration:none;word-break:break-all;">{{ $displayJoin }}</a>
                            </div>
                        @endif

                        @if(!empty($customMessage))
                            <div style="margin-top:16px;padding:12px 14px;background:#f8f9fa;border-radius:6px;font-size:14px;color:#3c4043;line-height:1.55;">
                                {{ $customMessage }}
                            </div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px;"><div style="border-top:1px solid #e8eaed;"></div></td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 8px;">
                        <div style="font-size:20px;font-weight:700;color:#202124;">Your session starts soon</div>
                        <div style="margin-top:10px;font-size:14px;color:#3c4043;line-height:1.55;">
                            Hello <strong>{{ $name }}</strong>, this is a reminder about your upcoming {{ $meetBrand }} appointment.
                        </div>
                        @if(!empty($nextSession))
                            <div style="margin-top:12px;font-size:14px;color:#202124;"><strong>{{ $nextSession }}</strong></div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 8px;"><div style="border-top:1px solid #e8eaed;"></div></td>
                </tr>

                <tr>
                    <td style="padding:8px 28px 24px;">
                        <table role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                @if(!empty($cancelUrl))
                                    <td style="padding-right:10px;">
                                        <a href="{{ $cancelUrl }}" target="_blank"
                                           style="display:inline-block;background:#ffffff;color:#1a73e8;text-decoration:none;font-weight:600;font-size:13px;padding:10px 16px;border-radius:4px;border:1px solid #dadce0;">
                                            Cancel appointment
                                        </a>
                                    </td>
                                @endif
                                @if(!empty($bookAnotherUrl))
                                    <td>
                                        <a href="{{ $bookAnotherUrl }}" target="_blank"
                                           style="display:inline-block;background:#ffffff;color:#1a73e8;text-decoration:none;font-weight:600;font-size:13px;padding:10px 16px;border-radius:4px;border:1px solid #dadce0;">
                                            Book another appointment
                                        </a>
                                    </td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8f9fa;padding:16px 28px;font-size:12px;color:#5f6368;line-height:1.55;">
                        Powered by {{ $appName }} appointment scheduling
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
