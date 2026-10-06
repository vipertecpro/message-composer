<?php

namespace Vipertecpro\MessageComposer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Vipertecpro\MessageComposer\PendingEmail email()
 * @method static \Vipertecpro\MessageComposer\PendingSms sms()
 * @method static array{email: bool, sms: bool, smsAttachments: bool, smsSubject: bool} capabilities()
 * @method static bool canSendEmail()
 * @method static bool canSendSms()
 *
 * @see \Vipertecpro\MessageComposer\MessageComposer
 */
class MessageComposer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vipertecpro\MessageComposer\MessageComposer::class;
    }
}
