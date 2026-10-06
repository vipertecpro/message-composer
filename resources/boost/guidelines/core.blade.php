## vipertecpro/message-composer

A NativePHP Mobile plugin with hand-written Swift and Kotlin that opens the
system email composer and the system SMS / iMessage composer, pre-filled, and
reports the outcome as an event. Works with NativePHP Mobile v4.

### What it does / does not do

- It never sends anything by itself. The user reviews and sends in the system
  composer (iOS) or their mail / messaging app (Android).
- Attachments are absolute paths to LOCAL files. Download remote files first.
- `open()` is asynchronous and returns the request id; the outcome arrives as
  `EmailComposerClosed` / `SmsComposerClosed`.
- Android cannot tell whether the user sent the message: its result is always
  `handedOff`, delivered when the user returns to the app. iOS reports `sent`,
  `saved` (email drafts), `cancelled` or `failed`.
- Outside a native app (tests, web) `capabilities()` is all `false` and
  `open()` does nothing. Invalid input still throws `InvalidArgumentException`.

### Facade methods

`use Vipertecpro\MessageComposer\Facades\MessageComposer;`

- `MessageComposer::email(): PendingEmail` — chain `to()`, `cc()`, `bcc()` (string, comma list or array of addresses), `subject()`, `body()` (plain) or `html()`, `attach($path, ?$name, ?$mime)`, `fallbackToMailto(bool)` (iOS without a Mail account; default true), `id()`, then `open()`.
- `MessageComposer::sms(): PendingSms` — chain `to()` (phone numbers, or iMessage email addresses), `body()`, `subject()` (only where supported), `attach()`, `id()`, then `open()`.
- `MessageComposer::capabilities(): array{email: bool, sms: bool, smsAttachments: bool, smsSubject: bool}` — no prompt.
- `MessageComposer::canSendEmail(): bool`, `MessageComposer::canSendSms(): bool`.
- A builder that goes out of scope opens itself; call `discard()` to drop it.

### Events

- `Vipertecpro\MessageComposer\Events\EmailComposerClosed(string $result, ?string $id, ?string $reason, ?string $message)`
- `Vipertecpro\MessageComposer\Events\SmsComposerClosed(string $result, ?string $id, ?string $reason, ?string $message)`
- `result`: `sent`, `saved`, `cancelled`, `failed` (reason `unavailable`, `attachment_failed`, `error`), `handedOff`.

### Example (SuperNative NativeComponent)

```php
use Native\Mobile\Attributes\On;
use Vipertecpro\MessageComposer\Events\EmailComposerClosed;
use Vipertecpro\MessageComposer\Facades\MessageComposer;

public function contactSupport(): void
{
    MessageComposer::email()
        ->to('support@example.com')
        ->subject('Feedback')
        ->body('Hi…')
        ->attach(storage_path('logs/laravel.log'), 'app-log.txt')
        ->open();
}

#[On(EmailComposerClosed::class)]
public function onClosed(string $result, ?string $reason = null): void
{
    // sent | saved | cancelled | failed | handedOff
}
```

### Do

- Check `canSendEmail()` / `canSendSms()` before showing a send button, and offer a fallback (show the address) when false.
- Treat `handedOff` as "probably sent"; never as confirmed delivery.
- Shrink photos before attaching (e.g. `PhotoKit::compress()` from vipertecpro/photo-kit).

### Don't

- Don't request `SEND_SMS` or try to send without the user — this plugin deliberately cannot.
- Don't pass URLs as attachments.
