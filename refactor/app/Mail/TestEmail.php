<?php

namespace DTApi\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public object $data)
    {
        //
    }

    public function envelope()
    {
        return new Envelope(
            subject: $this->data->subject,
        );
    }

    public function content()
    {
        return new Content(
            view: $this->data->view,
            with: ['name' => $this->data->name],
        );
    }
}
