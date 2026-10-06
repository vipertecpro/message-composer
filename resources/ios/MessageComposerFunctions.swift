import Foundation
import UIKit
import MessageUI

// =============================================================================
// MessageComposer — iOS native side
// =============================================================================
//
// Three bridge functions, no third-party code:
//   • Capabilities  — MFMailComposeViewController.canSendMail() and the
//                     MFMessageComposeViewController checks. Synchronous.
//   • ComposeEmail  — present MFMailComposeViewController pre-filled with
//                     recipients, subject, plain or HTML body and attachments.
//                     Without a Mail account, optionally open the mailto: link
//                     in the default mail app instead. Result via event.
//   • ComposeSms    — present MFMessageComposeViewController (SMS / iMessage)
//                     with recipients, body, subject and attachments.
//
// The user always reviews and sends the message themselves.
// =============================================================================

// MARK: - Bridge functions

enum MessageComposerFunctions {
    class Capabilities: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // Class-level checks; safe off the main thread, so no hop is
            // needed (a main.sync here could deadlock a busy main thread).
            return [
                "email": MFMailComposeViewController.canSendMail(),
                "sms": MFMessageComposeViewController.canSendText(),
                "smsAttachments": MFMessageComposeViewController.canSendAttachments(),
                "smsSubject": MFMessageComposeViewController.canSendSubject(),
            ]
        }
    }

    class ComposeEmail: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let request = EmailRequest(parameters)
            DispatchQueue.main.async { ComposerPresenter.shared.presentEmail(request) }
            return [:]
        }
    }

    class ComposeSms: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let request = SmsRequest(parameters)
            DispatchQueue.main.async { ComposerPresenter.shared.presentSms(request) }
            return [:]
        }
    }
}

// MARK: - Events

enum MessageComposerEvents {
    static let email = "Vipertecpro\\MessageComposer\\Events\\EmailComposerClosed"
    static let sms = "Vipertecpro\\MessageComposer\\Events\\SmsComposerClosed"

    /// Deliver on the main thread with nil values dropped, so PHP's event
    /// constructor only receives the keys that have a value.
    static func send(_ event: String, result: String, id: String?, reason: String? = nil, message: String? = nil) {
        var payload: [String: Any] = ["result": result]
        if let id { payload["id"] = id }
        if let reason { payload["reason"] = reason }
        if let message, !message.isEmpty { payload["message"] = message }
        let deliver: () -> Void = { LaravelBridge.shared.send?(event, payload) }
        if Thread.isMainThread {
            deliver()
        } else {
            DispatchQueue.main.async(execute: deliver)
        }
    }
}

// MARK: - Requests

struct Attachment {
    let path: String
    let name: String
    let mime: String

    static func list(_ raw: Any?) -> [Attachment] {
        guard let items = raw as? [Any] else { return [] }
        return items.compactMap { item in
            guard let map = item as? [String: Any], let path = map["path"] as? String, !path.isEmpty else { return nil }
            let name = (map["name"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? (path as NSString).lastPathComponent
            let mime = (map["mime"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? "application/octet-stream"
            return Attachment(path: path, name: name, mime: mime)
        }
    }
}

private func strings(_ raw: Any?) -> [String] {
    (raw as? [Any])?.compactMap { $0 as? String }.filter { !$0.isEmpty } ?? []
}

struct EmailRequest {
    let id: String?
    let to: [String]
    let cc: [String]
    let bcc: [String]
    let subject: String
    let body: String
    let isHtml: Bool
    let attachments: [Attachment]
    let fallbackToMailto: Bool
    let mailto: String?

    init(_ p: [String: Any]) {
        id = p["id"] as? String
        to = strings(p["to"])
        cc = strings(p["cc"])
        bcc = strings(p["bcc"])
        subject = p["subject"] as? String ?? ""
        body = p["body"] as? String ?? ""
        isHtml = p["isHtml"] as? Bool ?? false
        attachments = Attachment.list(p["attachments"])
        fallbackToMailto = p["fallbackToMailto"] as? Bool ?? true
        mailto = p["mailto"] as? String
    }
}

struct SmsRequest {
    let id: String?
    let to: [String]
    let body: String
    let subject: String?
    let attachments: [Attachment]

    init(_ p: [String: Any]) {
        id = p["id"] as? String
        to = strings(p["to"])
        body = p["body"] as? String ?? ""
        subject = (p["subject"] as? String).flatMap { $0.isEmpty ? nil : $0 }
        attachments = Attachment.list(p["attachments"])
    }
}

// MARK: - Presenter

/// Owns the composer delegates (MessageUI holds them weakly) and maps each
/// outcome to one result event. One composer at a time: a second request
/// while one is on screen fails with `busy` instead of stacking sheets.
final class ComposerPresenter: NSObject, MFMailComposeViewControllerDelegate, MFMessageComposeViewControllerDelegate {
    static let shared = ComposerPresenter()

    private var pendingEmailId: String?
    private var pendingSmsId: String?
    private var isPresenting = false

    // MARK: Email

    func presentEmail(_ request: EmailRequest) {
        guard !isPresenting else {
            MessageComposerEvents.send(MessageComposerEvents.email, result: "failed", id: request.id, reason: "error", message: "Another composer is already open.")
            return
        }

        guard MFMailComposeViewController.canSendMail() else {
            openMailto(request)
            return
        }

        guard let top = Self.topViewController() else {
            MessageComposerEvents.send(MessageComposerEvents.email, result: "failed", id: request.id, reason: "error", message: "No screen to present the composer on.")
            return
        }

        let composer = MFMailComposeViewController()
        composer.mailComposeDelegate = self
        if !request.to.isEmpty { composer.setToRecipients(request.to) }
        if !request.cc.isEmpty { composer.setCcRecipients(request.cc) }
        if !request.bcc.isEmpty { composer.setBccRecipients(request.bcc) }
        composer.setSubject(request.subject)
        composer.setMessageBody(request.body, isHTML: request.isHtml)

        for attachment in request.attachments {
            guard let data = FileManager.default.contents(atPath: attachment.path) else {
                MessageComposerEvents.send(MessageComposerEvents.email, result: "failed", id: request.id, reason: "attachment_failed", message: "Could not read \(attachment.name).")
                return
            }
            composer.addAttachmentData(data, mimeType: attachment.mime, fileName: attachment.name)
        }

        pendingEmailId = request.id
        isPresenting = true
        top.present(composer, animated: true)
    }

    /// No Mail account: hand the email to whichever app handles mailto:.
    private func openMailto(_ request: EmailRequest) {
        guard request.fallbackToMailto, let raw = request.mailto, let url = URL(string: raw) else {
            MessageComposerEvents.send(MessageComposerEvents.email, result: "failed", id: request.id, reason: "unavailable", message: "No mail account is set up on this device.")
            return
        }

        UIApplication.shared.open(url, options: [:]) { opened in
            if opened {
                let note = request.attachments.isEmpty ? nil : "Opened in the default mail app without attachments (mailto: links cannot carry files)."
                MessageComposerEvents.send(MessageComposerEvents.email, result: "handedOff", id: request.id, message: note)
            } else {
                MessageComposerEvents.send(MessageComposerEvents.email, result: "failed", id: request.id, reason: "unavailable", message: "No mail account is set up and no app handles mailto: links.")
            }
        }
    }

    func mailComposeController(_ controller: MFMailComposeViewController, didFinishWith result: MFMailComposeResult, error: Error?) {
        let id = pendingEmailId
        pendingEmailId = nil

        let name: String
        switch result {
        case .sent: name = "sent"
        case .saved: name = "saved"
        case .cancelled: name = "cancelled"
        case .failed: name = "failed"
        @unknown default: name = "failed"
        }

        controller.dismiss(animated: true) { [weak self] in
            self?.isPresenting = false
            MessageComposerEvents.send(
                MessageComposerEvents.email,
                result: name,
                id: id,
                reason: name == "failed" ? "error" : nil,
                message: error?.localizedDescription
            )
        }
    }

    // MARK: SMS

    func presentSms(_ request: SmsRequest) {
        guard !isPresenting else {
            MessageComposerEvents.send(MessageComposerEvents.sms, result: "failed", id: request.id, reason: "error", message: "Another composer is already open.")
            return
        }

        guard MFMessageComposeViewController.canSendText() else {
            MessageComposerEvents.send(MessageComposerEvents.sms, result: "failed", id: request.id, reason: "unavailable", message: "This device cannot send text messages.")
            return
        }

        if !request.attachments.isEmpty && !MFMessageComposeViewController.canSendAttachments() {
            MessageComposerEvents.send(MessageComposerEvents.sms, result: "failed", id: request.id, reason: "attachment_failed", message: "This device cannot attach files to messages.")
            return
        }

        guard let top = Self.topViewController() else {
            MessageComposerEvents.send(MessageComposerEvents.sms, result: "failed", id: request.id, reason: "error", message: "No screen to present the composer on.")
            return
        }

        let composer = MFMessageComposeViewController()
        composer.messageComposeDelegate = self
        if !request.to.isEmpty { composer.recipients = request.to }
        composer.body = request.body
        if let subject = request.subject, MFMessageComposeViewController.canSendSubject() {
            composer.subject = subject
        }

        for attachment in request.attachments {
            let url = URL(fileURLWithPath: attachment.path)
            guard composer.addAttachmentURL(url, withAlternateFilename: attachment.name) else {
                MessageComposerEvents.send(MessageComposerEvents.sms, result: "failed", id: request.id, reason: "attachment_failed", message: "Could not attach \(attachment.name).")
                return
            }
        }

        pendingSmsId = request.id
        isPresenting = true
        top.present(composer, animated: true)
    }

    func messageComposeViewController(_ controller: MFMessageComposeViewController, didFinishWith result: MessageComposeResult) {
        let id = pendingSmsId
        pendingSmsId = nil

        let name: String
        switch result {
        case .sent: name = "sent"
        case .cancelled: name = "cancelled"
        case .failed: name = "failed"
        @unknown default: name = "failed"
        }

        controller.dismiss(animated: true) { [weak self] in
            self?.isPresenting = false
            MessageComposerEvents.send(MessageComposerEvents.sms, result: name, id: id, reason: name == "failed" ? "error" : nil)
        }
    }

    // MARK: Helpers

    private static func topViewController() -> UIViewController? {
        let scene = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
            .first { $0.activationState == .foregroundActive }
        var top = scene?.windows.first { $0.isKeyWindow }?.rootViewController
        while let presented = top?.presentedViewController { top = presented }
        return top
    }
}
