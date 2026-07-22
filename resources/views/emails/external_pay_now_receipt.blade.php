<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Payment receipt</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.5;">
  <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
    <tr>
      @if(!empty($brandLogoUrl))
        <td style="width: 72px; vertical-align: middle;">
          <img src="{{ $brandLogoUrl }}" alt="{{ $brandName }}" style="max-height: 56px; max-width: 64px; object-fit: contain;">
        </td>
      @endif
      <td style="vertical-align: middle;">
        <h2 style="color: {{ $brandColor }}; margin: 0;">{{ $brandName }} — Payment receipt</h2>
      </td>
    </tr>
  </table>
  <p>Hello {{ $payment->payer_name ?: 'there' }},</p>
  <p>Thank you for your payment. A PDF receipt is attached.</p>
  <table style="border-collapse: collapse; margin: 1rem 0;">
    <tr><td style="padding: 4px 12px 4px 0; color: #555;">Course</td><td><strong>{{ $payment->course_title }}</strong></td></tr>
    <tr><td style="padding: 4px 12px 4px 0; color: #555;">Amount paid</td><td><strong>{{ number_format($payment->amount_rwf) }} {{ $payment->currency }}</strong></td></tr>
    <tr><td style="padding: 4px 12px 4px 0; color: #555;">Course max</td><td>{{ number_format($payment->course_price_rwf) }} {{ $payment->currency }}</td></tr>
    @if(($remainingDue ?? 0) > 0)
      <tr><td style="padding: 4px 12px 4px 0; color: #555;">Remaining due</td><td><strong>{{ number_format($remainingDue) }} {{ $payment->currency }}</strong></td></tr>
    @endif
    <tr><td style="padding: 4px 12px 4px 0; color: #555;">Status</td><td>{{ $statusLabel ?? 'Paid' }}</td></tr>
    <tr><td style="padding: 4px 12px 4px 0; color: #555;">Reference</td><td>{{ $payment->external_reference }}</td></tr>
    <tr><td style="padding: 4px 12px 4px 0; color: #555;">Paid at</td><td>{{ optional($payment->paid_at)->toDayDateTimeString() ?? now()->toDayDateTimeString() }}</td></tr>
    @if(!empty($brand['momo_receiver_name']) || !empty($brand['display_momo_phone']))
      <tr><td style="padding: 4px 12px 4px 0; color: #555;">Received by</td><td>{{ $brand['momo_receiver_name'] ?? $brandName }}@if(!empty($brand['display_momo_phone'])) ({{ $brand['display_momo_phone'] }})@endif</td></tr>
    @endif
  </table>
  <p style="color: #666; font-size: 13px;">This is an external Pay Now receipt and is not linked to a learner account.</p>
  <p>Regards,<br>{{ $brandName }}</p>
</body>
</html>
