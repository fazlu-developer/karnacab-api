<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerifyOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $audience = 'customer',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your KarnaRide email verification code');
    }

    public function content(): Content
    {
        $code = e($this->code);
        $app = $this->audience === 'driver' ? 'KarnaRide Driver app' : 'KarnaRide app';
        $html = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;background:#f4f1ea;font-family:Segoe UI,Arial,sans-serif;color:#10231c">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1ea;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #eadfcb">
        <tr><td style="background:#123328;color:#fff;padding:22px 28px;font-size:22px;font-weight:800">Karna<span style="color:#f5a623">Ride</span></td></tr>
        <tr><td style="padding:28px">
          <p style="margin:0 0 8px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#5c564c">Verify email</p>
          <h1 style="margin:0 0 12px;font-size:24px;line-height:1.25">Confirm your email</h1>
          <p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:#3d4a44">Use this code in the {$app}. It expires in 5 minutes.</p>
          <p style="margin:0;font-size:32px;letter-spacing:.28em;font-weight:800;color:#123328">{$code}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        return new Content(htmlString: $html);
    }
}
