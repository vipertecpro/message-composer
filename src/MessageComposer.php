<?php

namespace Vipertecpro\MessageComposer;

use InvalidArgumentException;

/**
 * Native email and SMS composers for NativePHP Mobile.
 *
 * Build a message with {@see email()} or {@see sms()}, then call `open()`.
 * The system composer appears pre-filled; the user reviews and sends it
 * themselves. The outcome arrives as an
 * {@see Events\EmailComposerClosed} / {@see Events\SmsComposerClosed} event.
 *
 * Nothing is ever sent silently — the platforms do not allow it, and this
 * plugin does not try.
 */
class MessageComposer
{
    /** Extension → MIME type for attachments whose type is not given. */
    public const MIME_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'json' => 'application/json', 'xml' => 'application/xml', 'html' => 'text/html', 'md' => 'text/markdown',
        'zip' => 'application/zip', 'ics' => 'text/calendar', 'vcf' => 'text/vcard', 'log' => 'text/plain',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v', '3gp' => 'video/3gpp',
    ];

    /** Start an email. Chain the fields, then call `open()`. */
    public function email(): PendingEmail
    {
        return new PendingEmail($this);
    }

    /** Start an SMS / iMessage. Chain the fields, then call `open()`. */
    public function sms(): PendingSms
    {
        return new PendingSms($this);
    }

    /**
     * What this device can do, without prompting.
     *
     * - `email`: a mail composer is available (iOS: a Mail account is set up;
     *   Android: an app handles `mailto:`).
     * - `sms`: the device can compose text messages.
     * - `smsAttachments`: photos and files can be attached to a message.
     * - `smsSubject`: a subject line is supported (iOS with MMS subjects on).
     *
     * Everything is `false` outside a native app.
     *
     * @return array{email: bool, sms: bool, smsAttachments: bool, smsSubject: bool}
     */
    public function capabilities(): array
    {
        $result = $this->call('MessageComposer.Capabilities');

        return [
            'email' => (bool) ($result['email'] ?? false),
            'sms' => (bool) ($result['sms'] ?? false),
            'smsAttachments' => (bool) ($result['smsAttachments'] ?? false),
            'smsSubject' => (bool) ($result['smsSubject'] ?? false),
        ];
    }

    public function canSendEmail(): bool
    {
        return $this->capabilities()['email'];
    }

    public function canSendSms(): bool
    {
        return $this->capabilities()['sms'];
    }

    /**
     * Normalise and validate an attachment.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function attachment(string $path, ?string $name = null, ?string $mime = null): array
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw new InvalidArgumentException('MessageComposer attaches local files only — download remote files first.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("MessageComposer: attachment not found at \"{$path}\".");
        }

        $name = trim((string) ($name ?? basename($path)));
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '_', $name) ?? '';

        if ($name === '' || $name === '.' || $name === '..') {
            $name = basename($path);
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: pathinfo($path, PATHINFO_EXTENSION));

        return [
            'path' => $path,
            'name' => $name,
            'mime' => $mime ?: (self::MIME_TYPES[$extension] ?? 'application/octet-stream'),
        ];
    }

    /**
     * Send a compose request over the bridge. A no-op outside a native app.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $method, array $payload): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        nativephp_call($method, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Call a synchronous bridge function and decode its result; a missing
     * bridge (tests, web) or an error response yields an empty array.
     *
     * @return array<string, mixed>
     */
    protected function call(string $method, array $params = []): array
    {
        if (! function_exists('nativephp_call')) {
            return [];
        }

        $raw = nativephp_call($method, json_encode((object) $params));
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (! is_array($decoded) || ($decoded['status'] ?? null) === 'error') {
            return [];
        }

        return $decoded;
    }
}
