<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        $partner = $this->user->role === 'DRIVER';

        return new Envelope(
            subject: $partner ? 'Welcome to KarnaRide Driver' : 'You registered successfully',
        );
    }

    public function content(): Content
    {
        $name = e($this->user->name ?: 'there');
        $partner = $this->user->role === 'DRIVER';
        $headline = $partner ? 'You are on the road with KarnaRide.' : 'You registered successfully.';
        $body = $partner
            ? 'Complete KYC when you can. We will email you when your driver account is active.'
            : 'Your KarnaRide account is ready. Book bikes, autos and cabs from the KarnaRide app.';

        $html = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;background:#f4f1ea;font-family:Segoe UI,Arial,sans-serif;color:#10231c">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1ea;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #eadfcb">
        <tr><td style="background:#123328;color:#fff;padding:22px 28px;font-size:22px;font-weight:800">Karna<span style="color:#f5a623">Ride</span></td></tr>
        <tr><td style="padding:28px">
          <p style="margin:0 0 8px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#5c564c">Welcome</p>
          <h1 style="margin:0 0 12px;font-size:26px;line-height:1.25">Hi {$name},</h1>
          <p style="margin:0 0 14px;font-size:16px;line-height:1.5">{$headline}</p>
          <p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:#3d4a44">{$body}</p>
          <p style="margin:0;font-size:13px;color:#5c564c">This email was sent because you added this address in the KarnaRide app.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        return new Content(htmlString: $html);
    }
}
