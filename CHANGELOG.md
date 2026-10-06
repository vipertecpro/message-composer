# Changelog

All notable changes to `vipertecpro/message-composer` are documented here.
The format is based on Keep a Changelog, and this project adheres to
Semantic Versioning.

## [1.0.0] - 2026-10-07

First release. Verified on the iOS Simulator (iPhone 17 Pro, iOS 26.5) and an
Android emulator (Pixel 9, API 36) against NativePHP Mobile 4.6; the native
iOS mail and message sheets need a physical device and were not exercised.

### Added
- **Email composer** — `MessageComposer::email()` with `to`, `cc`, `bcc`,
  `subject`, plain or HTML body and any number of attachments; presented with
  `MFMailComposeViewController` on iOS and handed to an email app with
  `ACTION_SENDTO` / `ACTION_SEND` and a `mailto:` selector on Android.
- **SMS / iMessage composer** — `MessageComposer::sms()` with recipients,
  body, attachments and subject; `MFMessageComposeViewController` on iOS, the
  default SMS app on Android.
- **Capabilities** — `capabilities()`, `canSendEmail()` and `canSendSms()`
  read what the device supports without prompting.
- **Result events** — `EmailComposerClosed` and `SmsComposerClosed` with
  `sent`, `saved`, `cancelled`, `failed` (with a reason) or `handedOff`.
- **mailto: fallback** on iOS when no Mail account is set up.
- **Android project setup** — a `post_compile` hook adds the `<queries>`
  block Android 11+ needs for package visibility.
- Validation before the bridge for addresses, phone numbers and attachments;
  a builder that failed validation is never opened.
- A JS bridge for legacy web-view apps and Laravel Boost guidelines.

### Notes
- Zero third-party native dependencies and no permissions.
- Android does not report whether a message was sent; it reports `handedOff`.
