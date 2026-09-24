<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WorkspaceWelcome extends Mailable
{
    public function __construct(public string $workspace, public string $role, public bool $test = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->test ? '[Test] ' : '').'You’re invited to '.$this->workspace.' on OPAL');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.workspace-welcome');
    }
}
