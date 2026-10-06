<?php

namespace Vipertecpro\MessageComposer\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when the native email composer closes.
 *
 * @property string $result `sent`, `saved` (email drafts, iOS), `cancelled`, `failed` or `handedOff`.
 *                          `handedOff` means another app took the message and the platform does not say
 *                          whether it was sent — always the case on Android.
 * @property ?string $id The id of the PendingMessage that opened the composer.
 * @property ?string $reason For `failed`: `unavailable`, `attachment_failed` or `error`.
 * @property ?string $message A human-readable detail from the platform, when there is one.
 */
class EmailComposerClosed
{
    use Dispatchable, SerializesModels;

    public const SENT = 'sent';

    public const SAVED = 'saved';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const HANDED_OFF = 'handedOff';

    public function __construct(
        public string $result = self::HANDED_OFF,
        public ?string $id = null,
        public ?string $reason = null,
        public ?string $message = null,
    ) {}

    public function wasSent(): bool
    {
        return $this->result === self::SENT;
    }

    public function wasCancelled(): bool
    {
        return $this->result === self::CANCELLED;
    }

    public function failed(): bool
    {
        return $this->result === self::FAILED;
    }
}
