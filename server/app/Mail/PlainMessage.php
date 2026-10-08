<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An e-mail from a message template: plain text (reads well everywhere), with an optional attachment from private storage. */
class PlainMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $text, public ?string $attachmentPath = null, public ?string $attachmentName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.plain', with: ['text' => $this->text]);
    }

    public function attachments(): array
    {
        return $this->attachmentPath ? [Attachment::fromStorageDisk('local', $this->attachmentPath)->as($this->attachmentName ?? basename($this->attachmentPath))] : [];
    }
}
