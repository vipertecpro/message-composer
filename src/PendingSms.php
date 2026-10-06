<?php

namespace Vipertecpro\MessageComposer;

/**
 * A text message waiting to be shown in the native SMS / iMessage composer.
 *
 *     MessageComposer::sms()
 *         ->to('+1 555 123 4567')
 *         ->body('Your table is ready.')
 *         ->open();
 */
class PendingSms extends PendingMessage
{
    /** @var list<string> */
    protected array $to = [];

    protected string $body = '';

    protected ?string $subject = null;

    /**
     * Phone numbers (digits, spaces, `+ - ( ) . * #`) or, for iMessage on
     * iOS, email addresses.
     *
     * @param  string|iterable<string>  $recipients
     */
    public function to(string|iterable $recipients): static
    {
        foreach ($this->listOf($recipients) as $recipient) {
            $isPhone = preg_match('/^[+0-9()\-\s.*#]{2,32}$/', $recipient) === 1 && preg_match('/\d/', $recipient) === 1;

            if (! $isPhone && filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                $this->reject("MessageComposer: \"{$recipient}\" is not a phone number or iMessage address.");
            }

            $this->to[] = $recipient;
        }

        return $this;
    }

    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** MMS subject — used only where the device supports it (`capabilities()['smsSubject']`). */
    public function subject(?string $subject): static
    {
        $this->subject = $subject === null ? null : str_replace(["\r", "\n"], ' ', $subject);

        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'body' => $this->body,
            'subject' => $this->subject,
            'attachments' => $this->attachments,
        ];
    }

    protected function bridgeMethod(): string
    {
        return 'MessageComposer.ComposeSms';
    }
}
