/**
 * Message Composer Plugin for NativePHP Mobile — JavaScript bridge (legacy web-view apps).
 *
 * NOTE: the primary consumer in v4 is a SuperNative `NativeComponent` calling
 * the PHP facade (`MessageComposer::email()->…->open()`) and handling the
 * `EmailComposerClosed` / `SmsComposerClosed` events with `#[On]`. This JS
 * wrapper is provided for Livewire/Inertia web-view screens. Composing is
 * asynchronous — subscribe to the result with the `#nativephp` `On()` helper.
 * Validation of addresses and attachments happens in the PHP layer only.
 *
 * @example
 *   import { messageComposer } from '@vipertecpro/message-composer';
 *   import { On } from '#nativephp';
 *
 *   On('native:Vipertecpro\\MessageComposer\\Events\\EmailComposerClosed', ({ result }) => {
 *       // sent | saved | cancelled | failed | handedOff
 *   });
 *
 *   const { email } = await messageComposer.capabilities();
 *   if (email) {
 *       await messageComposer.email({ to: ['support@example.com'], subject: 'Feedback', body: 'Hi…' });
 *   }
 */

const baseUrl = '/_native/api/call';

/**
 * Internal bridge call function.
 * @private
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    return result.data ?? result;
}

/** @private */
function newId() {
    return globalThis.crypto?.randomUUID?.() ?? `mc-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * Attachment as the native side expects it.
 *
 * @typedef {Object} Attachment
 * @property {string} path - Absolute path to a local file.
 * @property {string} [name] - File name shown to the recipient.
 * @property {string} [mime] - MIME type (default: application/octet-stream).
 */

/**
 * Open the native email composer. The outcome arrives as `EmailComposerClosed`.
 *
 * @param {Object} message
 * @param {string[]} [message.to]
 * @param {string[]} [message.cc]
 * @param {string[]} [message.bcc]
 * @param {string} [message.subject]
 * @param {string} [message.body]
 * @param {boolean} [message.isHtml]
 * @param {Attachment[]} [message.attachments]
 * @param {boolean} [message.fallbackToMailto] - iOS without a Mail account (default true).
 * @param {string} [message.id] - Correlation id echoed on the event.
 * @returns {Promise<string>} The id.
 */
export async function email(message = {}) {
    const id = message.id || newId();
    const to = message.to || [];
    const query = new URLSearchParams();
    if (message.cc?.length) query.set('cc', message.cc.join(','));
    if (message.bcc?.length) query.set('bcc', message.bcc.join(','));
    if (message.subject) query.set('subject', message.subject);
    if (message.body && !message.isHtml) query.set('body', message.body);
    const qs = query.toString().replace(/\+/g, '%20');

    await bridgeCall('MessageComposer.ComposeEmail', {
        id,
        to,
        cc: message.cc || [],
        bcc: message.bcc || [],
        subject: message.subject || '',
        body: message.body || '',
        isHtml: !!message.isHtml,
        attachments: (message.attachments || []).map(normaliseAttachment),
        fallbackToMailto: message.fallbackToMailto !== false,
        mailto: `mailto:${to.map(encodeURIComponent).join(',')}${qs ? `?${qs}` : ''}`,
    });

    return id;
}

/**
 * Open the native SMS / iMessage composer. The outcome arrives as `SmsComposerClosed`.
 *
 * @param {Object} message
 * @param {string[]} [message.to] - Phone numbers or iMessage addresses.
 * @param {string} [message.body]
 * @param {string} [message.subject]
 * @param {Attachment[]} [message.attachments]
 * @param {string} [message.id]
 * @returns {Promise<string>} The id.
 */
export async function sms(message = {}) {
    const id = message.id || newId();

    await bridgeCall('MessageComposer.ComposeSms', {
        id,
        to: message.to || [],
        body: message.body || '',
        subject: message.subject || null,
        attachments: (message.attachments || []).map(normaliseAttachment),
    });

    return id;
}

/**
 * What this device can compose, without prompting.
 *
 * @returns {Promise<{email: boolean, sms: boolean, smsAttachments: boolean, smsSubject: boolean}>}
 */
export async function capabilities() {
    const result = await bridgeCall('MessageComposer.Capabilities');

    return {
        email: !!result.email,
        sms: !!result.sms,
        smsAttachments: !!result.smsAttachments,
        smsSubject: !!result.smsSubject,
    };
}

/** @private */
function normaliseAttachment(attachment) {
    const item = typeof attachment === 'string' ? { path: attachment } : attachment;

    return {
        path: item.path,
        name: item.name || item.path.split('/').pop(),
        mime: item.mime || 'application/octet-stream',
    };
}

export const messageComposer = { email, sms, capabilities };

export default messageComposer;
