<?php

namespace Vipertecpro\MessageComposer;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared behaviour of {@see PendingEmail} and {@see PendingSms}: an id for
 * correlating the result event, attachments, and opening once — either when
 * `open()` is called or, like NativePHP's own pending builders, when the
 * builder goes out of scope.
 */
abstract class PendingMessage
{
    protected string $id;

    /** @var list<array{path: string, name: string, mime: string}> */
    protected array $attachments = [];

    protected bool $opened = false;

    public function __construct(protected MessageComposer $composer)
    {
        $this->id = (string) Str::uuid();
    }

    /** Correlation id echoed back on the result event. A UUID by default. */
    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Attach a local file. The file name shown to the recipient defaults to
     * the file's own name; the MIME type is guessed from the extension.
     */
    public function attach(string $path, ?string $name = null, ?string $mime = null): static
    {
        try {
            $this->attachments[] = $this->composer->attachment($path, $name, $mime);
        } catch (InvalidArgumentException $e) {
            $this->reject($e->getMessage());
        }

        return $this;
    }

    /** Open the native composer. Calling it twice opens it once. */
    public function open(): string
    {
        if (! $this->opened) {
            $this->opened = true;
            $this->composer->dispatch($this->bridgeMethod(), $this->toArray());
        }

        return $this->id;
    }

    /** Do not open the composer when this builder goes out of scope. */
    public function discard(): void
    {
        $this->opened = true;
    }

    /**
     * Invalid input: throw, and make sure this half-built message is never
     * opened when it goes out of scope.
     */
    protected function reject(string $message): never
    {
        $this->discard();

        throw new InvalidArgumentException($message);
    }

    /** @return array<string, mixed> The payload sent to the native side. */
    abstract public function toArray(): array;

    abstract protected function bridgeMethod(): string;

    /**
     * @param  string|iterable<string>  $values
     * @return list<string>
     */
    protected function listOf(string|iterable $values): array
    {
        $list = is_string($values) ? preg_split('/[,;]/', $values) : [...$values];

        return array_values(array_filter(array_map(fn ($value) => trim((string) $value), $list), fn (string $value) => $value !== ''));
    }

    public function __destruct()
    {
        $this->open();
    }
}
