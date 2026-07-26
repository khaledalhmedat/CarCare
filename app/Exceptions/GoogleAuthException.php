<?php

namespace App\Exceptions;

use Exception;

class GoogleAuthException extends Exception
{
    public function __construct(string $message, public int $status = 401)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('خدمة تسجيل الدخول عبر جوجل غير مهيأة على الخادم', 503);
    }

    public static function invalidToken(): self
    {
        return new self('رمز جوجل غير صالح أو منتهي الصلاحية', 401);
    }

    public static function unverifiedEmail(): self
    {
        return new self('بريد جوجل الإلكتروني غير موثّق، لا يمكن إتمام العملية', 422);
    }

    public static function inactiveAccount(): self
    {
        return new self('الحساب غير نشط، يرجى التواصل مع الدعم', 403);
    }
}
