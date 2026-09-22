<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TripInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Booking $booking,
        public readonly int $totalPaise,
        public readonly string $invoiceRef,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'KarnaCab invoice '.$this->invoiceRef,
        );
    }

    public function content(): Content
    {
        $name = e($this->user->name ?: 'there');
        $ref = e((string) ($this->booking->public_ref ?: $this->booking->id));
        $invoice = e($this->invoiceRef);
        $pickup = e((string) $this->booking->pickup_text);
        $drop = e((string) $this->booking->drop_text);
        $product = e(str_replace('_', ' ', (string) $this->booking->product));
        $fare = e(number_format($this->totalPaise / 100, 2));
        $mode = e(str_replace('_', ' ', (string) ($this->booking->payment_mode ?: 'CASH')));
        $when = e(optional($this->booking->updated_at)->format('d M Y, h:i A') ?: now()->format('d M Y, h:i A'));

        $html = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;background:#f4f1ea;font-family:Segoe UI,Arial,sans-serif;color:#10231c">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1ea;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #eadfcb">
        <tr><td style="background:#123328;color:#fff;padding:22px 28px">
          <div style="font-size:22px;font-weight:800">Karna<span style="color:#f5a623">Cab</span></div>
          <div style="font-size:13px;opacity:.85;margin-top:4px">Transport Service Pvt Ltd</div>
        </td></tr>
        <tr><td style="padding:28px">
          <p style="margin:0 0 8px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#5c564c">Trip invoice</p>
          <h1 style="margin:0 0 12px;font-size:26px">Hi {$name}, your trip is complete.</h1>
          <p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:#3d4a44">Thank you for riding with KarnaCab. This is your receipt for booking {$ref}.</p>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7f3ea;border-radius:14px">
            <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7">
              <strong>Invoice</strong> {$invoice}<br>
              <strong>When</strong> {$when}<br>
              <strong>Service</strong> {$product}<br>
              <strong>From</strong> {$pickup}<br>
              <strong>To</strong> {$drop}<br>
              <strong>Paid via</strong> {$mode}<br>
              <strong>Total</strong> ₹{$fare}
            </td></tr>
          </table>
          <p style="margin:18px 0 0;font-size:13px;color:#5c564c">Rate the trip in the KarnaCab app. This is a computer-generated invoice.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        return new Content(htmlString: $html);
    }
}
