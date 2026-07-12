<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Appointment confirmed</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#202124;">

@php
    $meetBrand = $meetBrand ?? 'XanderTech meet';
    $topicTitle = $topic ?? ('Meeting with ' . ($appName ?? 'Xander'));
    if (!empty($name)) {
        $topicTitle = $topicTitle . ' (' . $name . ')';
    }
    $companyName = $companyName ?? ($appName ?? 'Xander Global Scholars');
    $displayJoin = $joinUrlDisplay ?? $joinUrl ?? null;
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:560px;max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #dadce0;">

                {{-- Join CTA (Google Calendar–style) --}}
                <tr>
                    <td style="padding:28px 28px 8px;">
                        @if(!empty($joinUrl))
                            <div style="text-align:left;">
                                <a href="{{ $joinUrl }}" target="_blank"
                                   style="display:inline-block;background:#1a73e8;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 22px;border-radius:4px;">
                                    Join with {{ $meetBrand }}
                                </a>
                            </div>
                            <div style="margin-top:16px;font-size:14px;color:#3c4043;line-height:1.6;">
                                <span style="color:#5f6368;">Meeting link</span><br />
                                <a href="{{ $joinUrl }}" target="_blank" style="color:#1a73e8;text-decoration:none;word-break:break-all;">{{ $displayJoin }}</a>
                            </div>
                            <div style="margin-top:12px;font-size:13px;color:#5f6368;line-height:1.55;">
                                Test your setup any time before your appointment.
                            </div>
                            <div style="margin-top:6px;font-size:13px;color:#5f6368;line-height:1.55;">
                                New to {{ $meetBrand }}? Join from your browser — no extra app required.
                            </div>
                        @else
                            <div style="font-size:14px;color:#3c4043;line-height:1.6;">
                                Your appointment is confirmed. Your {{ $meetBrand }} join link will be available before the session.
                            </div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px;">
                        <div style="border-top:1px solid #e8eaed;"></div>
                    </td>
                </tr>

                {{-- Title + time --}}
                <tr>
                    <td style="padding:16px 28px 8px;">
                        <div style="font-size:20px;font-weight:700;color:#202124;line-height:1.35;">
                            {{ $topicTitle }}
                        </div>
                        @if(!empty($nextSession))
                            <div style="margin-top:10px;font-size:14px;color:#3c4043;line-height:1.55;">
                                {{ $nextSession }}
                            </div>
                        @endif
                        @if(!empty($duration))
                            <div style="margin-top:4px;font-size:13px;color:#5f6368;">
                                Duration: {{ $duration }}
                            </div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px;">
                        <div style="border-top:1px solid #e8eaed;"></div>
                    </td>
                </tr>

                {{-- Booking details --}}
                <tr>
                    <td style="padding:12px 28px 8px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#5f6368;width:120px;vertical-align:top;">Booked by</td>
                                <td style="padding:6px 0;font-size:14px;color:#202124;vertical-align:top;">
                                    <strong>{{ $name }}</strong>
                                    @if(!empty($recipientEmail))
                                        <div style="color:#5f6368;font-size:13px;margin-top:2px;">{{ $recipientEmail }}</div>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#5f6368;width:120px;vertical-align:top;">Company Name</td>
                                <td style="padding:6px 0;font-size:14px;color:#202124;vertical-align:top;">{{ $companyName }}</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#5f6368;width:120px;vertical-align:top;">Platform</td>
                                <td style="padding:6px 0;font-size:14px;color:#202124;vertical-align:top;">{{ $meetBrand }}</td>
                            </tr>
                            @if(!empty($learnerNotes))
                                <tr>
                                    <td style="padding:6px 0;font-size:13px;color:#5f6368;width:120px;vertical-align:top;">Notes</td>
                                    <td style="padding:6px 0;font-size:14px;color:#202124;vertical-align:top;">{{ $learnerNotes }}</td>
                                </tr>
                            @endif
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 8px;">
                        <div style="border-top:1px solid #e8eaed;"></div>
                    </td>
                </tr>

                {{-- Cancel / Book another --}}
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
                        Powered by {{ $appName }} appointment scheduling ·
                        <a href="{{ $bookAnotherUrl ?? '#' }}" style="color:#1a73e8;text-decoration:none;">Book another date</a>
                        <div style="margin-top:8px;">
                            You are receiving this email because you booked an appointment with {{ $companyName }}.
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
