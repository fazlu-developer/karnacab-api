<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $headline,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        $title = e($this->headline);
        $body = e($this->messageBody);
        $html = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;background:#f4f1ea;font-family:Segoe UI,Arial,sans-serif;color:#10231c">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1ea;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #eadfcb">
        <tr><td style="background:#123328;color:#fff;padding:22px 28px;font-size:22px;font-weight:800">Karna<span style="color:#f5a623">Cab</span></td></tr>
        <tr><td style="padding:28px">
          <h1 style="margin:0 0 12px;font-size:24px">{$title}</h1>
          <p style="margin:0;font-size:15px;line-height:1.6;color:#3d4a44">{$body}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        return new Content(htmlString: $html);
    }
}
