<?php

namespace Vipertecpro\MessageComposer;

/**
 * An email waiting to be shown in the native composer.
 *
 *     MessageComposer::email()
 *         ->to('support@example.com')
 *         ->subject('Feedback')
 *         ->body('Hi there…')
 *         ->attach(storage_path('logs/app.log'))
 *         ->open();
 */
class PendingEmail extends PendingMessage
{
    /** @var list<string> */
    protected array $to = [];

    /** @var list<string> */
    protected array $cc = [];

    /** @var list<string> */
    protected array $bcc = [];

    protected string $subject = '';

    protected string $body = '';

    protected bool $isHtml = false;

    protected bool $fallbackToMailto = true;

    /** @param  string|iterable<string>  $addresses  One address, a comma-separated string, or a list. */
    public function to(string|iterable $addresses): static
    {
        $this->to = [...$this->to, ...$this->addresses($addresses)];

        return $this;
    }

    /** @param  string|iterable<string>  $addresses */
    public function cc(string|iterable $addresses): static
    {
        $this->cc = [...$this->cc, ...$this->addresses($addresses)];

        return $this;
    }

    /** @param  string|iterable<string>  $addresses */
    public function bcc(string|iterable $addresses): static
    {
        $this->bcc = [...$this->bcc, ...$this->addresses($addresses)];

        return $this;
    }

    public function subject(string $subject): static
    {
        $this->subject = str_replace(["\r", "\n"], ' ', $subject);

        return $this;
    }

    /** Plain-text body. */
    public function body(string $body): static
    {
        $this->body = $body;
        $this->isHtml = false;

        return $this;
    }

    /** HTML body — rendered by the iOS composer and by Android mail apps that support it. */
    public function html(string $html): static
    {
        $this->body = $html;
        $this->isHtml = true;

        return $this;
    }

    /**
     * On iOS without a Mail account, hand the email to the default mail app
     * through a `mailto:` link instead (recipients, subject and body only —
     * links cannot carry attachments). On by default.
     */
    public function fallbackToMailto(bool $enabled = true): static
    {
        $this->fallbackToMailto = $enabled;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'body' => $this->body,
            'isHtml' => $this->isHtml,
            'attachments' => $this->attachments,
            'fallbackToMailto' => $this->fallbackToMailto,
            'mailto' => $this->mailtoUrl(),
        ];
    }

    /** The equivalent `mailto:` link (no attachments). */
    public function mailtoUrl(): string
    {
        $query = array_filter([
            'cc' => implode(',', $this->cc),
            'bcc' => implode(',', $this->bcc),
            'subject' => $this->subject,
            'body' => $this->isHtml ? trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $this->body) ?? ''))) : $this->body,
        ], fn (string $value) => $value !== '');

        return 'mailto:'.implode(',', array_map('rawurlencode', $this->to))
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    protected function bridgeMethod(): string
    {
        return 'MessageComposer.ComposeEmail';
    }

    /**
     * @param  string|iterable<string>  $addresses
     * @return list<string>
     */
    protected function addresses(string|iterable $addresses): array
    {
        $list = $this->listOf($addresses);

        foreach ($list as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->reject("MessageComposer: \"{$address}\" is not a valid email address.");
            }
        }

        return $list;
    }
}
