<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PropertyInquiryEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    /**
     * @param array $data Expects: property_title, property_url, name, email, phone, message, store_name
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    public function build()
    {
        $subject = 'New inquiry'.(! empty($this->data['property_title']) ? ': '.$this->data['property_title'] : '');

        return $this->subject($subject)
            ->view('emails.property_inquiry');
    }
}
