<?php

/**
 * Plugin structure tests for Message Composer — manifest, native files, PHP classes.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
    $this->swift = file_get_contents($this->pluginPath.'/resources/ios/MessageComposerFunctions.swift');
    $this->kotlin = file_get_contents($this->pluginPath.'/resources/android/MessageComposerFunctions.kt');
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();
        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        expect($this->manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions', 'version']);
        expect($this->manifest['name'])->toBe('vipertecpro/message-composer');
        expect($this->manifest['namespace'])->toBe('MessageComposer');
        expect($this->manifest['version'])->toBe('1.0.0');
    });

    it('exposes the three bridge functions', function () {
        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'android', 'ios', 'description']);
        }

        expect(array_column($this->manifest['bridge_functions'], 'name'))->toBe([
            'MessageComposer.Capabilities',
            'MessageComposer.ComposeEmail',
            'MessageComposer.ComposeSms',
        ]);
    });

    it('has marketplace metadata filled in', function () {
        expect($this->manifest['keywords'])->toBeArray()->not->toBeEmpty();
        expect($this->manifest['category'])->toBe('communication');
        expect($this->manifest['pricing']['type'])->toBe('free');
        expect($this->manifest['platforms'])->toBe(['android', 'ios']);
        expect($this->manifest['icon'])->toBe('resources/icon.png');
        expect(file_exists($this->pluginPath.'/resources/icon.png'))->toBeTrue();
        expect(array_slice(getimagesize($this->pluginPath.'/resources/icon.png'), 0, 2))->toBe([512, 512]);
    });

    it('needs no permissions on either platform', function () {
        expect($this->manifest['android']['permissions'])->toBe([]);
        expect($this->manifest['ios']['info_plist'])->toBe([]);
    });

    it('declares the two events', function () {
        expect($this->manifest['events'])->toBe([
            'Vipertecpro\\MessageComposer\\Events\\EmailComposerClosed',
            'Vipertecpro\\MessageComposer\\Events\\SmsComposerClosed',
        ]);

        foreach ($this->manifest['events'] as $event) {
            expect(class_exists($event))->toBeTrue();
        }
    });
});

describe('Native Code', function () {
    it('has every bridge class on both platforms', function () {
        expect($this->kotlin)->toContain('package com.vipertecpro.plugins.message_composer')
            ->toContain('object MessageComposerFunctions');
        expect($this->swift)->toContain('enum MessageComposerFunctions');

        foreach ($this->manifest['bridge_functions'] as $function) {
            $android = explode('.', $function['android']);
            $ios = explode('.', $function['ios']);
            expect($this->kotlin)->toContain('class '.end($android).'(');
            expect($this->swift)->toContain('class '.end($ios).':');
        }
    });

    it('dispatches the declared events from both platforms', function () {
        foreach ($this->manifest['events'] as $event) {
            $escaped = str_replace('\\', '\\\\', $event);
            expect($this->swift)->toContain($escaped);
            expect($this->kotlin)->toContain($escaped);
        }
    });

    it('uses the system composers and never sends on its own', function () {
        expect($this->swift)->toContain('MFMailComposeViewController')->toContain('MFMessageComposeViewController');
        expect($this->kotlin)->toContain('ACTION_SENDTO')->toContain('FileProvider')->toContain('handedOff');
        expect($this->kotlin)->not->toContain('SmsManager');
        expect($this->kotlin)->not->toContain('SEND_SMS');
    });

    it('emits only result values the events document', function () {
        $documented = ['sent', 'saved', 'cancelled', 'failed', 'handedOff'];

        preg_match_all('/result: "(\w+)"|"result" to "(\w+)"|, "(\w+)", id/', $this->swift.$this->kotlin, $matches);
        $used = array_filter([...$matches[1], ...$matches[2], ...$matches[3]]);

        expect($used)->not->toBeEmpty();
        foreach ($used as $result) {
            expect($result)->toBeIn($documented);
        }
    });
});

describe('PHP Classes', function () {
    it('has the service provider, facade and builders', function () {
        foreach (['MessageComposerServiceProvider', 'MessageComposer', 'PendingMessage', 'PendingEmail', 'PendingSms', 'Facades/MessageComposer'] as $class) {
            expect(file_exists($this->pluginPath."/src/{$class}.php"))->toBeTrue("missing {$class}");
        }

        $facade = file_get_contents($this->pluginPath.'/src/Facades/MessageComposer.php');
        foreach (['email()', 'sms()', 'capabilities()', 'canSendEmail()', 'canSendSms()'] as $method) {
            expect($facade)->toContain($method);
        }
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['name'])->toBe('vipertecpro/message-composer');
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['license'])->toBe('MIT');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
        expect($composer['extra']['laravel']['providers'])->toBe(['Vipertecpro\\MessageComposer\\MessageComposerServiceProvider']);
    });
});

describe('Lifecycle Hooks', function () {
    it('has valid hooks configuration', function () {
        foreach (array_keys($this->manifest['hooks']) as $hook) {
            expect($hook)->toBeIn(['pre_compile', 'post_compile', 'copy_assets', 'post_build']);
        }
    });

    it('has a post_compile hook command with the manifest signature', function () {
        $content = file_get_contents($this->pluginPath.'/src/Commands/ConfigureCommand.php');

        expect($content)->toContain('extends NativePluginHookCommand')
            ->toContain('$signature = \''.$this->manifest['hooks']['post_compile'].'\'')
            ->toContain('$this->isAndroid()');
    });
});

describe('Documentation', function () {
    it('ships the product files', function () {
        foreach (['README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'RELEASING.md', 'LICENSE', 'resources/boost/guidelines/core.blade.php', 'resources/js/messageComposer.js'] as $file) {
            expect(file_exists($this->pluginPath.'/'.$file))->toBeTrue("missing {$file}");
        }
    });

    it('keeps the README free of links', function () {
        $readme = file_get_contents($this->pluginPath.'/README.md');

        expect($readme)->not->toMatch('/\]\(/');
        expect($readme)->not->toMatch('/https?:\/\//');
        expect($readme)->not->toContain('<a ');
        expect(trim(last(explode("\n", trim($readme)))))->toContain('vipertecpro.com');
    });

    it('lists the 1.0.0 release in the changelog', function () {
        expect(file_get_contents($this->pluginPath.'/CHANGELOG.md'))->toContain('## [1.0.0]');
    });
});
