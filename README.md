# Message Composer for NativePHP — native email and SMS composers

Open the system **email composer** and the system **SMS / iMessage composer**
from PHP, pre-filled with recipients, a subject, a plain-text or **HTML body**
and **attachments** — then get a **result event** back when the composer
closes. Hand-written Swift (MessageUI) and Kotlin (intents + FileProvider)
behind one PHP API, **zero third-party native libraries** and **no permissions**.

Typical uses: "Contact support" with the app log attached, "Share this invoice
by email", "Text the guest that their table is ready", "Invite a friend".

The user always reviews and sends the message themselves. Nothing is ever sent
silently — the platforms do not allow it, and this plugin does not try.

## Features

- **Email composer** — to, cc, bcc, subject, plain or HTML body, any number of attachments
- **SMS / iMessage composer** — recipients, body, photos and files (MMS / iMessage), subject where supported
- **Capabilities first** — know whether email, texting, attachments and subjects are available before showing a button
- **Result events** — `EmailComposerClosed` and `SmsComposerClosed` with `sent`, `saved`, `cancelled`, `failed` or `handedOff`
- **Graceful fallback** — on iOS without a Mail account the email is handed to the default mail app as a `mailto:` link
- **Validation before the bridge** — bad addresses, phone numbers and missing files throw in PHP, never half-open a composer
- **No permissions** — nothing to declare, nothing for the user to approve
- **Zero dependencies** — no third-party native libraries
- **iOS + Android** behind one PHP API

## Requirements

- PHP 8.4+
- NativePHP Mobile v4 (`nativephp/mobile: ^4.0`) — tested on iOS and Android against 4.6
- iOS 15+ / Android 10+ (API 29)

## Installation

```bash
composer require vipertecpro/message-composer
php artisan vendor:publish --tag=nativephp-plugins-provider   # once per app
php artisan native:plugin:register vipertecpro/message-composer
php artisan native:plugin:list       # verify "MessageComposer" and its three functions appear
php artisan native:run ios           # or: android — rebuild so the native code compiles in
```

> Requiring with Composer is **not** enough — an unregistered plugin does
> nothing. Always run `native:plugin:register` and confirm with
> `native:plugin:list`.

## Permissions and project setup

| Platform | What the plugin adds | Why |
|---|---|---|
| iOS | nothing | `MFMailComposeViewController` and `MFMessageComposeViewController` need no Info.plist keys |
| Android | a `<queries>` block in `AndroidManifest.xml` (mailto, smsto, send) | Android 11+ hides other apps unless you declare what you look for — without it `capabilities()` would always say "no" |

The Android `<queries>` block is written by the plugin's `post_compile` hook
on every build, between two marker comments, so you never edit the manifest by
hand. No runtime permission is requested on either platform — in particular,
the plugin never asks for `SEND_SMS`; the user's own messaging app sends.

## Usage

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Vipertecpro\MessageComposer\Events\EmailComposerClosed;
use Vipertecpro\MessageComposer\Facades\MessageComposer;

class Support extends NativeComponent
{
    public bool $canEmail = false;
    public string $status = '';

    public function mount(): void
    {
        $this->canEmail = MessageComposer::canSendEmail();
    }

    public function contactSupport(): void
    {
        MessageComposer::email()
            ->to('support@example.com')
            ->subject('Help with order #4521')
            ->body("Hi, I need help with…\n\nApp version 2.3.0")
            ->attach(storage_path('logs/laravel.log'), 'app-log.txt')
            ->open();
    }

    #[On(EmailComposerClosed::class)]
    public function onClosed(string $result, ?string $reason = null): void
    {
        $this->status = match ($result) {
            'sent' => 'Thanks — we got your message.',
            'saved' => 'Saved to your drafts.',
            'cancelled' => '',
            'handedOff' => 'Finish sending in your mail app.',
            default => $reason === 'unavailable' ? 'Please email support@example.com' : 'Could not open email.',
        };
    }
}
```

```blade
@if ($canEmail)
    <native:button label="Contact support" icon="envelope" @press="contactSupport" />
@endif
<native:text class="text-sm">{{ $status }}</native:text>
```

### Email

```php
$id = MessageComposer::email()
    ->to('a@example.com, b@example.com')     // a string, a comma list or an array
    ->cc(['boss@example.com'])
    ->bcc('archive@example.com')
    ->subject('Invoice 2026-0042')
    ->html('<p>Your invoice is attached.</p>')   // or ->body('plain text')
    ->attach($pdfPath)                          // name and MIME type from the file
    ->attach($photoPath, 'receipt.jpg', 'image/jpeg')
    ->id('invoice-42')                          // echoed on the event; a UUID by default
    ->open();                                   // returns the id
```

On iOS without a Mail account the email is handed to the default mail app
through a `mailto:` link (recipients, subject and a plain-text version of the
body; links cannot carry attachments) and the event reports `handedOff`.
Turn that off with `->fallbackToMailto(false)` to get `failed` /
`unavailable` instead.

Tip: photos straight from the camera can be several megabytes. Shrink them
first with the free **Photo Kit** plugin (`PhotoKit::compress()`), or crop
them with the free **Image Cropper**, then attach the result.

### SMS / iMessage

```php
MessageComposer::sms()
    ->to(['+1 555 010 0199', 'friend@icloud.com'])  // phone numbers, or iMessage addresses on iOS
    ->body('Your table for two is ready.')
    ->attach($photoPath)                             // needs MMS or iMessage
    ->subject('Table 4')                             // only where capabilities()['smsSubject'] is true
    ->open();
```

### Capabilities

```php
MessageComposer::capabilities();
// ['email' => true, 'sms' => true, 'smsAttachments' => true, 'smsSubject' => false]

MessageComposer::canSendEmail();   // bool
MessageComposer::canSendSms();     // bool
```

Read these before showing a "Send" button. Nothing is prompted. Outside a
native app (tests, web) everything is `false` and `open()` does nothing.

### API

```php
MessageComposer::email(): PendingEmail;
MessageComposer::sms(): PendingSms;
MessageComposer::capabilities(): array;
MessageComposer::canSendEmail(): bool;
MessageComposer::canSendSms(): bool;

PendingEmail: to(), cc(), bcc(), subject(), body(), html(), attach(), fallbackToMailto(), id(), open(), discard(), toArray(), mailtoUrl()
PendingSms:   to(), body(), subject(), attach(), id(), open(), discard(), toArray()
```

Like NativePHP's own pending builders, a builder that goes out of scope
without `open()` opens itself; call `discard()` to drop it. A builder that
threw on invalid input is never opened.

Invalid input throws `InvalidArgumentException` before the bridge is touched:
an invalid email address, a recipient that is neither a phone number nor an
email address, an attachment that does not exist, or a remote URL as an
attachment.

### Events

| Event | Payload | Fired when |
|---|---|---|
| `Vipertecpro\MessageComposer\Events\EmailComposerClosed` | `string $result`, `?string $id`, `?string $reason`, `?string $message` | The email composer closed, or could not open. |
| `Vipertecpro\MessageComposer\Events\SmsComposerClosed` | `string $result`, `?string $id`, `?string $reason`, `?string $message` | The message composer closed, or could not open. |

| `result` | Meaning |
|---|---|
| `sent` | The user tapped Send (iOS). For email this means queued in the Outbox. |
| `saved` | The email was saved as a draft (iOS). |
| `cancelled` | The user closed the composer without sending (iOS). |
| `handedOff` | Another app took the message — Android always, and iOS with the `mailto:` fallback. The platform does not say whether it was sent. On Android the event arrives when the user comes back to your app. |
| `failed` | With `reason` = `unavailable` (no mail account / mail app / messaging), `attachment_failed`, or `error`. |

Both events have `wasSent()`, `wasCancelled()` and `failed()` helpers, and
constants for every result (`EmailComposerClosed::HANDED_OFF`, …).

### Legacy web-view apps

A JS bridge is shipped at `resources/js/messageComposer.js` with `email()`,
`sms()` and `capabilities()`. Results arrive as the same native events —
subscribe with the `#nativephp` `On()` helper.

## Limitations

- **Android cannot report the outcome.** Mail and SMS apps do not tell the
  calling app whether the user sent the message, so Android always reports
  `handedOff` when the user returns. iOS reports `sent`, `saved` or
  `cancelled` exactly.
- **iOS needs the Mail app with an account** for the native email sheet. The
  iOS Simulator has neither, so there the composer reports `failed` /
  `unavailable` (or `handedOff` when another mail app handles `mailto:`).
- **Texting needs a device that can text.** The iOS Simulator and tablets
  without a SIM or iMessage report `sms: false`.
- **SMS attachments need MMS or iMessage.** On Android they go to the default
  SMS app; an emulator without a carrier MMS configuration rejects them.
- **HTML email on Android** depends on the mail app: the HTML is passed along
  and a styled plain-text version is included for apps that ignore it.
- **One composer at a time on iOS.** A second request while one is open
  reports `failed` / `error`.
- **Local files only** for attachments; download remote files first. Very
  large attachments may be refused by the mail or messaging app.

## Verified on

- iOS Simulator, iPhone 17 Pro (iOS 26.5): capabilities (all `false`, as the
  Simulator has no Mail account or Messages), the `mailto:` fallback path and
  the `failed` / `unavailable` events for email and SMS, validation errors.
- Android emulator, Pixel 9 (API 36): capabilities (email, SMS and
  attachments `true`), the email handed to Gmail with two attachments, the SMS
  composer in Google Messages pre-filled with the number and text, the photo
  attachment delivered to Messages (which refuses it on the emulator because
  it has no MMS configuration), and `handedOff` on return.
- Not verified: the native iOS mail and message sheets (`sent`, `saved`,
  `cancelled`) — they need a physical iPhone with Mail and a SIM or iMessage.

## Demo

The companion demo app **free-plugins-demo** contains three ready-made
screens — capabilities, Email and Text message — each a small
`NativeComponent` you can copy from.

## Contributing

Issues and pull requests are welcome. See the `CONTRIBUTING.md` file included
with the package for local setup, the project layout and how it works.

## Changelog

See the `CHANGELOG.md` file included with the package for the full version history.

## Licence

MIT — see the `LICENSE` file included with the package.

vipertecpro is an independent developer. NativePHP, Laravel, Apple, Google, Firebase and other names are trademarks of their respective owners; this package is not affiliated with or endorsed by them. iOS and Apple are trademarks of Apple Inc. Android, Google Play and Firebase are trademarks of Google LLC.

Message Composer is a free plugin from vipertecpro.com, home of the paid plugins for NativePHP Mobile: Rich-Text Editor, Onboarding & Tours, Health Data and Native Charts.
