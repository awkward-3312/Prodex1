<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MarketingCampaignEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    /**
     * @param array $data Expects: subject, body, company_name, and optional attachment (absolute path)
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    public function build()
    {
        $email = $this->subject($this->data['subject'])
            ->markdown('emails.custom')
            ->view('emails.custom');

        if (! empty($this->data['attachment']) && is_file($this->data['attachment'])) {
            $email->attach($this->data['attachment']);
        }

        return $email;
    }
}
