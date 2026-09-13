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
        return new Envelope(
            subject: 'Welcome to KarnaCab',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Hi '.e($this->user->name).',</p>'
                .'<p>Your KarnaCab account is ready. Book rides across <strong>Bihar</strong> and <strong>Delhi</strong> with your number '
                .e($this->user->phone).'.</p>'
                .'<p>Thank you for riding with KarnaCab.</p>',
        );
    }
}
