<?php

use Vipertecpro\MessageComposer\Commands\ConfigureCommand;
use Vipertecpro\MessageComposer\Events\EmailComposerClosed;
use Vipertecpro\MessageComposer\Events\SmsComposerClosed;
use Vipertecpro\MessageComposer\MessageComposer;

/**
 * Behaviour of the PHP layer: building and validating messages before the
 * bridge, and what the native side receives. nativephp_call() is absent here,
 * so open() is a silent no-op and the payload is read with toArray().
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/message-composer-tests-'.uniqid();
    mkdir($this->dir);
    $this->pdf = $this->dir.'/invoice.pdf';
    file_put_contents($this->pdf, '%PDF-1.4');
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

describe('email()', function () {
    it('builds the payload the native composer reads', function () {
        $email = (new MessageComposer)->email()
            ->id('feedback-1')
            ->to('support@example.com, sales@example.com')
            ->cc(['boss@example.com'])
            ->bcc('archive@example.com')
            ->subject("Order #42\nrefund")
            ->body('Hello')
            ->attach($this->pdf);

        expect($email->toArray())->toMatchArray([
            'id' => 'feedback-1',
            'to' => ['support@example.com', 'sales@example.com'],
            'cc' => ['boss@example.com'],
            'bcc' => ['archive@example.com'],
            'subject' => 'Order #42 refund',
            'body' => 'Hello',
            'isHtml' => false,
            'fallbackToMailto' => true,
            'attachments' => [['path' => $this->pdf, 'name' => 'invoice.pdf', 'mime' => 'application/pdf']],
        ]);

        $email->discard();
    });

    it('marks HTML bodies and keeps a plain-text mailto fallback', function () {
        $email = (new MessageComposer)->email()->to('a@example.com')->subject('Hi there')->html('<p>Line one</p><p>Line &amp; two</p>');

        expect($email->toArray()['isHtml'])->toBeTrue()
            ->and($email->mailtoUrl())->toBe('mailto:a%40example.com?subject=Hi%20there&body=Line%20one%0ALine%20%26%20two');

        $email->discard();
    });

    it('builds a bare mailto link when nothing is filled in', function () {
        $email = (new MessageComposer)->email();

        expect($email->mailtoUrl())->toBe('mailto:')
            ->and($email->toArray()['to'])->toBe([]);

        $email->discard();
    });

    it('rejects invalid addresses before the bridge', function (string $method) {
        expect(fn () => (new MessageComposer)->email()->{$method}('not-an-email'))
            ->toThrow(InvalidArgumentException::class, 'not a valid email address');
    })->with(['to', 'cc', 'bcc']);

    it('generates a UUID id and opens once', function () {
        $email = (new MessageComposer)->email();

        expect($email->getId())->toMatch('/^[0-9a-f-]{36}$/')
            ->and($email->open())->toBe($email->getId())
            ->and($email->open())->toBe($email->getId());
    });

    it('can turn the mailto fallback off', function () {
        $email = (new MessageComposer)->email()->fallbackToMailto(false);

        expect($email->toArray()['fallbackToMailto'])->toBeFalse();

        $email->discard();
    });
});

describe('sms()', function () {
    it('accepts phone numbers and iMessage addresses', function () {
        $sms = (new MessageComposer)->sms()
            ->to(['+1 (555) 123-4567', 'friend@icloud.com'])
            ->to('*123#')
            ->body('Your table is ready')
            ->subject("Table\n4");

        expect($sms->toArray())->toMatchArray([
            'to' => ['+1 (555) 123-4567', 'friend@icloud.com', '*123#'],
            'body' => 'Your table is ready',
            'subject' => 'Table 4',
            'attachments' => [],
        ]);

        $sms->discard();
    });

    it('rejects recipients that are neither numbers nor addresses', function (string $recipient) {
        expect(fn () => (new MessageComposer)->sms()->to($recipient))
            ->toThrow(InvalidArgumentException::class, 'not a phone number');
    })->with(['hello', '+', 'call me maybe']);
});

describe('attachments', function () {
    it('guesses the MIME type and cleans the display name', function () {
        $attachment = (new MessageComposer)->attachment($this->pdf, 'Q3: report/final?.pdf');

        expect($attachment)->toBe(['path' => $this->pdf, 'name' => 'Q3_ report_final_.pdf', 'mime' => 'application/pdf']);
    });

    it('falls back to a generic type and honours an explicit one', function () {
        $blob = $this->dir.'/data.bin';
        file_put_contents($blob, 'x');

        expect((new MessageComposer)->attachment($blob)['mime'])->toBe('application/octet-stream')
            ->and((new MessageComposer)->attachment($blob, null, 'text/plain')['mime'])->toBe('text/plain');
    });

    it('rejects missing and remote files', function () {
        expect(fn () => (new MessageComposer)->attachment($this->dir.'/missing.pdf'))
            ->toThrow(InvalidArgumentException::class, 'attachment not found')
            ->and(fn () => (new MessageComposer)->attachment('https://example.com/a.pdf'))
            ->toThrow(InvalidArgumentException::class, 'local files only');
    });

    it('lists only lowercase extensions', function () {
        foreach (array_keys(MessageComposer::MIME_TYPES) as $extension) {
            expect($extension)->toBe(strtolower($extension));
        }
    });
});

describe('capabilities()', function () {
    it('reports nothing outside a native app', function () {
        $composer = new MessageComposer;

        expect($composer->capabilities())->toBe(['email' => false, 'sms' => false, 'smsAttachments' => false, 'smsSubject' => false])
            ->and($composer->canSendEmail())->toBeFalse()
            ->and($composer->canSendSms())->toBeFalse();
    });
});

describe('events', function () {
    it('carry the native payload by name', function (string $class) {
        $event = new $class(result: 'failed', id: 'x', reason: 'unavailable', message: 'No mail account');

        expect($event->result)->toBe('failed')
            ->and($event->id)->toBe('x')
            ->and($event->reason)->toBe('unavailable')
            ->and($event->failed())->toBeTrue()
            ->and($event->wasSent())->toBeFalse()
            ->and((new $class(result: 'sent'))->wasSent())->toBeTrue()
            ->and((new $class(result: 'cancelled'))->wasCancelled())->toBeTrue();
    })->with([EmailComposerClosed::class, SmsComposerClosed::class]);
});

describe('Android project setup', function () {
    it('inserts one queries block before <application>, idempotently', function () {
        $manifest = "<manifest>\n    <uses-permission android:name=\"x\" />\n\n    <application\n        android:label=\"App\">\n    </application>\n</manifest>\n";

        $once = ConfigureCommand::withQueries($manifest);
        $twice = ConfigureCommand::withQueries($once);

        expect($twice)->toBe($once)
            ->and(substr_count($once, '<queries>'))->toBe(1)
            ->and($once)->toContain('android:scheme="mailto"')
            ->and($once)->toContain('android:scheme="smsto"')
            ->and(strpos($once, '<queries>'))->toBeLessThan(strpos($once, '<application'));
    });

    it('leaves a manifest without <application> untouched', function () {
        expect(ConfigureCommand::withQueries('<manifest />'))->toBe('<manifest />');
    });
});

describe('invalid input', function () {
    it('never opens a half-built message', function () {
        $opened = [];
        $composer = new class($opened) extends MessageComposer
        {
            public function __construct(public array &$opened) {}

            public function dispatch(string $method, array $payload): void
            {
                $this->opened[] = $method;
            }
        };

        try {
            $composer->email()->to('nope');
        } catch (InvalidArgumentException) {
        }

        try {
            $composer->sms()->attach('/missing/file.jpg');
        } catch (InvalidArgumentException) {
        }

        $composer->sms()->to('+15550100');

        expect($opened)->toBe(['MessageComposer.ComposeSms']);
    });
});
