<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebsiteLeadMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Lead $lead) {}

    public function envelope(): Envelope
    {
        $type = strtoupper((string) $this->lead->type);

        return new Envelope(
            subject: 'KarnaCab website enquiry · '.$type.' #'.$this->lead->id,
            replyTo: array_values(array_filter([(string) ($this->lead->email ?: '')])),
        );
    }

    public function content(): Content
    {
        $name = e((string) $this->lead->name);
        $type = e((string) $this->lead->type);
        $phone = e((string) $this->lead->phone);
        $email = e((string) ($this->lead->email ?: '—'));
        $district = e((string) ($this->lead->district ?: '—'));
        $message = nl2br(e((string) $this->lead->message));
        $payload = e((string) ($this->lead->payload ?: ''));
        $payloadBlock = $payload === '' ? '' : '<p style="margin:16px 0 0;font-size:13px;color:#5c564c"><b>Extra</b><br>'.$payload.'</p>';

        $html = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;background:#f4f1ea;font-family:Segoe UI,Arial,sans-serif;color:#10231c">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1ea;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #eadfcb">
        <tr><td style="background:#123328;color:#fff;padding:22px 28px;font-size:22px;font-weight:800">Karna<span style="color:#f5a623">Cab</span></td></tr>
        <tr><td style="padding:28px">
          <p style="margin:0 0 8px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#5c564c">Website lead</p>
          <h1 style="margin:0 0 12px;font-size:24px;line-height:1.25">{$type} enquiry from {$name}</h1>
          <p style="margin:0 0 8px;font-size:15px"><b>Phone:</b> {$phone}</p>
          <p style="margin:0 0 8px;font-size:15px"><b>Email:</b> {$email}</p>
          <p style="margin:0 0 16px;font-size:15px"><b>District:</b> {$district}</p>
          <p style="margin:0;font-size:15px;line-height:1.55">{$message}</p>
          {$payloadBlock}
          <p style="margin:22px 0 0;font-size:13px;color:#5c564c">Open Admin → Website leads to update status.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        return new Content(htmlString: $html);
    }
}
