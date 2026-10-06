package com.vipertecpro.plugins.message_composer

// =============================================================================
// MessageComposer — Android native side
// =============================================================================
//
// Three bridge functions, no third-party code (mirrors the iOS implementation):
//   • Capabilities  — which apps can take a mailto: / smsto: intent, whether
//                     the device can message at all, and whether a default
//                     SMS app exists for attachments. Synchronous.
//   • ComposeEmail  — ACTION_SENDTO mailto: (text only) or ACTION_SEND /
//                     SEND_MULTIPLE with a mailto: selector (attachments), so
//                     only email apps are offered. Result via event.
//   • ComposeSms    — ACTION_SENDTO smsto: (text only) or ACTION_SEND to the
//                     default SMS app (attachments). Result via event.
//
// Android apps do not report whether the user actually sent the message, so
// every successful hand-off ends with result "handedOff" once the user comes
// back to the app. Attachments are copied into the cache directory, which the
// NativePHP FileProvider already exposes, and shared with read-only grants.
// =============================================================================

import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Telephony
import android.text.Html
import android.util.Log
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.FileProvider
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.UUID

object MessageComposerFunctions {

    internal const val TAG = "MessageComposer"
    internal const val EVENT_EMAIL = "Vipertecpro\\MessageComposer\\Events\\EmailComposerClosed"
    internal const val EVENT_SMS = "Vipertecpro\\MessageComposer\\Events\\SmsComposerClosed"

    // ------------------------------------------------------------ Bridge

    class Capabilities(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val pm = activity.packageManager
            val canEmail = Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:")).resolveActivity(pm) != null
            val hasTelephony = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                pm.hasSystemFeature(PackageManager.FEATURE_TELEPHONY_MESSAGING)
            } else {
                pm.hasSystemFeature(PackageManager.FEATURE_TELEPHONY)
            }
            val canSms = hasTelephony && Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:")).resolveActivity(pm) != null
            val defaultSmsApp = Telephony.Sms.getDefaultSmsPackage(activity)

            return mapOf(
                "email" to canEmail,
                "sms" to canSms,
                "smsAttachments" to (canSms && defaultSmsApp != null),
                "smsSubject" to false,
            )
        }
    }

    class ComposeEmail(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters.string("id")
            Handler(Looper.getMainLooper()).post {
                try {
                    val attachments = shareable(activity, parameters["attachments"])
                    launch(activity, emailIntent(parameters, attachments), EVENT_EMAIL, id)
                } catch (e: AttachmentException) {
                    dispatch(activity, EVENT_EMAIL, "failed", id, "attachment_failed", e.message)
                }
            }
            return emptyMap()
        }
    }

    class ComposeSms(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters.string("id")
            Handler(Looper.getMainLooper()).post {
                try {
                    val attachments = shareable(activity, parameters["attachments"])
                    val intent = smsIntent(activity, parameters, attachments)
                    if (intent == null) {
                        dispatch(activity, EVENT_SMS, "failed", id, "unavailable", "No default SMS app is set, so files cannot be attached.")
                    } else {
                        launch(activity, intent, EVENT_SMS, id)
                    }
                } catch (e: AttachmentException) {
                    dispatch(activity, EVENT_SMS, "failed", id, "attachment_failed", e.message)
                }
            }
            return emptyMap()
        }
    }

    // ------------------------------------------------------------ Intents

    private fun emailIntent(p: Map<String, Any>, attachments: List<Uri>): Intent {
        val to = p.strings("to").toTypedArray()
        val cc = p.strings("cc").toTypedArray()
        val bcc = p.strings("bcc").toTypedArray()
        val subject = p.string("subject") ?: ""
        val body = p.string("body") ?: ""
        val isHtml = p["isHtml"] as? Boolean ?: false

        val intent = when {
            attachments.isEmpty() -> Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:"))
            // No MIME type on the main intent: with a selector, the selector
            // alone picks the app, and a type here makes resolution fail.
            attachments.size == 1 -> Intent(Intent.ACTION_SEND).apply {
                putExtra(Intent.EXTRA_STREAM, attachments.first())
            }
            else -> Intent(Intent.ACTION_SEND_MULTIPLE).apply {
                putParcelableArrayListExtra(Intent.EXTRA_STREAM, ArrayList(attachments))
            }
        }

        if (attachments.isNotEmpty()) {
            // Offer email apps only, not every app that accepts a share.
            intent.selector = Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:"))
            grantRead(intent, attachments)
        }

        if (to.isNotEmpty()) intent.putExtra(Intent.EXTRA_EMAIL, to)
        if (cc.isNotEmpty()) intent.putExtra(Intent.EXTRA_CC, cc)
        if (bcc.isNotEmpty()) intent.putExtra(Intent.EXTRA_BCC, bcc)
        intent.putExtra(Intent.EXTRA_SUBJECT, subject)

        if (isHtml) {
            intent.putExtra(Intent.EXTRA_HTML_TEXT, body)
            intent.putExtra(Intent.EXTRA_TEXT, Html.fromHtml(body, Html.FROM_HTML_MODE_COMPACT))
        } else {
            intent.putExtra(Intent.EXTRA_TEXT, body)
        }

        return intent
    }

    /** Null when attachments were requested but there is no default SMS app to receive them. */
    private fun smsIntent(context: Context, p: Map<String, Any>, attachments: List<Uri>): Intent? {
        val recipients = p.strings("to").joinToString(";")
        val body = p.string("body") ?: ""

        if (attachments.isEmpty()) {
            return Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:" + Uri.encode(recipients))).apply {
                putExtra("sms_body", body)
            }
        }

        val smsApp = Telephony.Sms.getDefaultSmsPackage(context) ?: return null
        val mimes = attachmentMimes(p["attachments"])
        val intent = if (attachments.size == 1) {
            Intent(Intent.ACTION_SEND).apply { putExtra(Intent.EXTRA_STREAM, attachments.first()) }
        } else {
            Intent(Intent.ACTION_SEND_MULTIPLE).apply { putParcelableArrayListExtra(Intent.EXTRA_STREAM, ArrayList(attachments)) }
        }

        return intent.apply {
            type = if (mimes.size == 1) mimes.first() else "*/*"
            setPackage(smsApp)
            putExtra("address", recipients)
            putExtra("sms_body", body)
            putExtra(Intent.EXTRA_TEXT, body)
            grantRead(this, attachments)
        }
    }

    private fun grantRead(intent: Intent, uris: List<Uri>) {
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        val clip = ClipData.newRawUri("attachment", uris.first())
        uris.drop(1).forEach { clip.addItem(ClipData.Item(it)) }
        intent.clipData = clip
    }

    // ------------------------------------------------------------ Attachments

    private class AttachmentException(message: String) : Exception(message)

    /**
     * Copy each attachment into cache/message-composer/<uuid>/<name> (the
     * folder the NativePHP FileProvider exposes) and return content:// URIs.
     */
    private fun shareable(context: Context, raw: Any?): List<Uri> {
        val items = list(raw)
        if (items.isEmpty()) return emptyList()

        val folder = File(context.cacheDir, "message-composer/${UUID.randomUUID()}")
        if (!folder.mkdirs() && !folder.isDirectory) throw AttachmentException("Could not prepare the attachment folder.")

        val authority = "${context.packageName}.fileprovider"
        return items.map { item ->
            val path = item.optString("path")
            val source = File(path)
            if (!source.isFile) throw AttachmentException("No file at $path")
            val name = item.optString("name").takeIf { it.isNotBlank() } ?: source.name
            val copy = File(folder, name.replace(Regex("[\\\\/:*?\"<>|]"), "_"))
            try {
                source.copyTo(copy, overwrite = true)
                FileProvider.getUriForFile(context, authority, copy)
            } catch (e: Exception) {
                Log.e(TAG, "attachment failed: ${e.message}", e)
                throw AttachmentException("Could not attach $name: ${e.message}")
            }
        }
    }

    private fun attachmentMimes(raw: Any?): Set<String> =
        list(raw).map { it.optString("mime").ifBlank { "*/*" } }.toSet()

    private fun list(raw: Any?): List<JSONObject> = when (raw) {
        is JSONArray -> (0 until raw.length()).mapNotNull { raw.optJSONObject(it) }
        is List<*> -> raw.mapNotNull { (it as? Map<*, *>)?.let { map -> JSONObject(map) } ?: it as? JSONObject }
        else -> emptyList()
    }

    // ------------------------------------------------------------ Launch + result

    private fun launch(activity: FragmentActivity, intent: Intent, event: String, id: String?) {
        try {
            ComposerCoordinator.install(activity).launch(intent, event, id)
        } catch (e: ActivityNotFoundException) {
            val what = if (event == EVENT_EMAIL) "No email app is installed." else "No messaging app is installed."
            dispatch(activity, event, "failed", id, "unavailable", what)
        } catch (e: Exception) {
            Log.e(TAG, "launch failed: ${e.message}", e)
            dispatch(activity, event, "failed", id, "error", e.message)
        }
    }

    internal fun dispatch(activity: FragmentActivity, event: String, result: String, id: String?, reason: String? = null, message: String? = null) {
        val payload = JSONObject().apply {
            put("result", result)
            id?.let { put("id", it) }
            reason?.let { put("reason", it) }
            message?.takeIf { it.isNotBlank() }?.let { put("message", it) }
        }
        NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
    }

    // ------------------------------------------------------------ Params

    private fun Map<String, Any>.string(key: String): String? =
        (this[key] as? String)?.takeIf { it.isNotEmpty() }

    private fun Map<String, Any>.strings(key: String): List<String> = when (val raw = this[key]) {
        is JSONArray -> (0 until raw.length()).mapNotNull { raw.optString(it).takeIf { s -> s.isNotBlank() } }
        is List<*> -> raw.mapNotNull { (it as? String)?.takeIf { s -> s.isNotBlank() } }
        else -> emptyList()
    }
}

/**
 * Headless fragment that owns the ActivityResultLauncher, so the result of
 * the mail / SMS app comes back here even though bridge calls arrive long
 * after the activity started. Installed once per activity.
 */
class ComposerCoordinator : Fragment() {

    companion object {
        private const val FRAGMENT_TAG = "VipertecproMessageComposer"
        private const val KEY_EVENT = "pending_event"
        private const val KEY_ID = "pending_id"

        fun install(activity: FragmentActivity): ComposerCoordinator {
            val fm = activity.supportFragmentManager
            (fm.findFragmentByTag(FRAGMENT_TAG) as? ComposerCoordinator)?.let { return it }
            val coordinator = ComposerCoordinator()
            fm.beginTransaction().add(coordinator, FRAGMENT_TAG).commitNow()
            return coordinator
        }
    }

    private var pendingEvent: String? = null
    private var pendingId: String? = null
    private var leftApp = false
    private lateinit var launcher: ActivityResultLauncher<Intent>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        savedInstanceState?.let {
            pendingEvent = it.getString(KEY_EVENT)
            pendingId = it.getString(KEY_ID)
            leftApp = pendingEvent != null
        }

        // Mail and SMS apps answer RESULT_CANCELED whether or not the user
        // sent the message, and single-task apps (Messages) answer at once,
        // before the user has even seen the composer. So the result itself
        // is ignored; the event fires when the user is back in this app.
        launcher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { _ -> }
    }

    override fun onPause() {
        super.onPause()
        if (pendingEvent != null) leftApp = true
    }

    override fun onResume() {
        super.onResume()
        if (!leftApp) return
        leftApp = false
        val event = pendingEvent ?: return
        val id = pendingId
        pendingEvent = null
        pendingId = null
        activity?.let { MessageComposerFunctions.dispatch(it, event, "handedOff", id) }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putString(KEY_EVENT, pendingEvent)
        outState.putString(KEY_ID, pendingId)
    }

    fun launch(intent: Intent, event: String, id: String?) {
        val previous = pendingEvent
        if (previous != null) {
            // A new request before this app resumed (e.g. two quick taps);
            // close out the earlier one first so every request gets an event.
            activity?.let { MessageComposerFunctions.dispatch(it, previous, "handedOff", pendingId) }
        }
        pendingEvent = event
        pendingId = id
        leftApp = false
        try {
            launcher.launch(intent)
        } catch (e: Exception) {
            pendingEvent = null
            pendingId = null
            throw e
        }
    }
}

