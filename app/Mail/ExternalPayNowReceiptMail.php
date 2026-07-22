<?php

namespace App\Mail;

use App\Models\ExternalCoursePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ExternalPayNowReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $brand
     */
    public function __construct(
        public ExternalCoursePayment $payment,
        public string $pdfBinary,
        public string $pdfFilename,
        public array $brand = []
    ) {
    }

    public function build(): self
    {
        $brandName = (string) ($this->brand['brand_name'] ?? 'F&R Rwanda');
        $remaining = max(0, (int) $this->payment->course_price_rwf - (int) $this->payment->amount_rwf);

        return $this->subject('Payment receipt - ' . ($this->payment->course_title ?: $brandName))
            ->view('emails.external_pay_now_receipt')
            ->with([
                'payment' => $this->payment,
                'brand' => $this->brand,
                'brandName' => $brandName,
                'brandColor' => (string) ($this->brand['brand_primary_color'] ?? '#0070D0'),
                'brandLogoUrl' => $this->brand['brand_logo_url'] ?? null,
                'remainingDue' => $remaining,
                'statusLabel' => $remaining > 0 ? 'Partial paid' : 'Paid',
            ])
            ->attachData($this->pdfBinary, $this->pdfFilename, [
                'mime' => 'application/pdf',
            ]);
    }
}
