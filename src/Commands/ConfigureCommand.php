<?php

namespace Vipertecpro\MessageComposer\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * post_compile hook: project setup the manifest format cannot express.
 *
 * Android 11+ hides other apps from `resolveActivity()` unless the app
 * declares what it looks for in a `<queries>` block. Without it,
 * `MessageComposer::capabilities()` would always report `false` even though
 * Gmail or Messages is installed. This hook inserts (or refreshes) one
 * marker-delimited `<queries>` block in the generated AndroidManifest.xml.
 * iOS needs no project changes.
 */
class ConfigureCommand extends NativePluginHookCommand
{
    public const START_MARKER = '<!-- vipertecpro/message-composer queries: start -->';

    public const END_MARKER = '<!-- vipertecpro/message-composer queries: end -->';

    protected $signature = 'nativephp:message-composer:configure';

    protected $description = 'Add the Android package-visibility queries the Message Composer plugin needs';

    public function handle(): int
    {
        if (! $this->isAndroid()) {
            return self::SUCCESS;
        }

        $manifest = $this->buildPath().'/app/src/main/AndroidManifest.xml';

        if (! is_file($manifest)) {
            $this->warn("Message Composer: AndroidManifest.xml not found at {$manifest}; capabilities() may report false on Android 11+.");

            return self::SUCCESS;
        }

        $updated = self::withQueries((string) file_get_contents($manifest));
        file_put_contents($manifest, $updated);
        $this->info('Message Composer: package-visibility queries added to AndroidManifest.xml');

        return self::SUCCESS;
    }

    /** Insert or replace the queries block. Idempotent. */
    public static function withQueries(string $xml): string
    {
        $pattern = '/\n[ \t]*'.preg_quote(self::START_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'/s';
        $xml = preg_replace($pattern, '', $xml) ?? $xml;

        $block = "\n    ".self::START_MARKER."\n"
            ."    <queries>\n"
            ."        <intent>\n            <action android:name=\"android.intent.action.SENDTO\" />\n            <data android:scheme=\"mailto\" />\n        </intent>\n"
            ."        <intent>\n            <action android:name=\"android.intent.action.SENDTO\" />\n            <data android:scheme=\"smsto\" />\n        </intent>\n"
            ."        <intent>\n            <action android:name=\"android.intent.action.SEND\" />\n            <data android:mimeType=\"*/*\" />\n        </intent>\n"
            ."    </queries>\n"
            .'    '.self::END_MARKER;

        $position = strpos($xml, '<application');

        if ($position === false) {
            return $xml;
        }

        $lineStart = strrpos(substr($xml, 0, $position), "\n");
        $lineStart = $lineStart === false ? $position : $lineStart;

        return substr($xml, 0, $lineStart).$block.substr($xml, $lineStart);
    }
}
