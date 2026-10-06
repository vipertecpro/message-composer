# Contributing

Thanks for helping improve **Message Composer**. This is a NativePHP Mobile
plugin with a thin PHP layer and hand-written native code (Swift on iOS,
Kotlin on Android). Contributions of all kinds are welcome — bug reports,
docs, and code.

## Getting set up

Work on the plugin alongside a NativePHP app by pointing Composer at your local
checkout:

```json
"repositories": [
    { "type": "path", "url": "../message-composer" }
]
```

```bash
composer require vipertecpro/message-composer:@dev
php artisan native:plugin:register vipertecpro/message-composer
php artisan native:run ios       # or: android — recompiles the native code
```

## Running the tests

```bash
vendor/bin/pest
```

Please keep tests green and add coverage for behaviour you change. The PHP
tests cover building and validating messages, the payload sent to the native
side, the events and the Android manifest hook; native behaviour is verified
by hand on a simulator / emulator and, for the iOS sheets, on a device.

## Project layout

```
src/MessageComposer.php                          entry point — builders, capabilities, attachments, bridge
src/PendingMessage.php                           shared builder: id, attachments, open once, discard
src/PendingEmail.php                             email builder (to, cc, bcc, subject, body/html, mailto fallback)
src/PendingSms.php                               SMS / iMessage builder (to, body, subject)
src/Facades/MessageComposer.php                  the MessageComposer facade
src/Events/EmailComposerClosed.php               email composer outcome (result, id, reason, message)
src/Events/SmsComposerClosed.php                 message composer outcome (result, id, reason, message)
src/Commands/ConfigureCommand.php                post_compile hook — Android <queries> block
resources/ios/MessageComposerFunctions.swift     MessageUI implementation
resources/android/MessageComposerFunctions.kt    intents + FileProvider implementation
resources/js/messageComposer.js                  JS bridge for legacy web-view apps
resources/boost/guidelines/core.blade.php        Laravel Boost / AI usage guidelines
nativephp.json                                   plugin manifest: bridge_functions, events, hooks
```

## How it works

```
PHP  MessageComposer::email()->to(…)->attach(…)->open()
  └─ nativephp_call("MessageComposer.ComposeEmail", {...})     ← fire-and-forget
        ├─ iOS:     MFMailComposeViewController (or the mailto: fallback)
        │           → delegate didFinishWith → sent | saved | cancelled | failed
        ├─ Android: copy attachments to cache/message-composer/<uuid>/ → FileProvider URIs
        │           → ACTION_SENDTO mailto: / ACTION_SEND(_MULTIPLE) + mailto: selector
        │           → user returns to the app → handedOff
        └─ dispatch EmailComposerClosed { result, id, reason, message }

PHP  MessageComposer::capabilities()                           ← synchronous
  └─ nativephp_call("MessageComposer.Capabilities") → { email, sms, smsAttachments, smsSubject }
```

Both platforms produce the same event payloads, so app code never branches on
the platform — only the set of results differs (Android cannot see `sent`).

## Verifying native changes

1. Build the plugin into a demo app on the iOS Simulator and an Android
   emulator (`php artisan native:run ios` / `android`).
2. Capabilities: Android with Gmail and Messages reports email, SMS and
   attachments; the iOS Simulator reports all `false`.
3. Android: open an email with two attachments and confirm the email app
   receives them; open an SMS and confirm Messages shows the number and text;
   come back and confirm `handedOff` arrives once.
4. iOS (device with Mail and a SIM): send, save a draft and cancel, and
   confirm `sent`, `saved` and `cancelled`.
