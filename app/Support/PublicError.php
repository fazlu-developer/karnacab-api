<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PublicError
{
    public static function message(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $msg = $e->getMessage();

            return $msg !== '' ? $msg : 'Please check the details and try again.';
        }
        if ($e instanceof HttpExceptionInterface) {
            $msg = trim($e->getMessage());
            if ($msg !== '' && ! self::isInternal($msg)) {
                return $msg;
            }
        }

        return self::fallback($e);
    }

    public static function isInternal(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'sqlstate')
            || str_contains($lower, 'sql:')
            || str_contains($lower, 'connection: mysql')
            || str_contains($lower, 'unknown column')
            || str_contains($lower, 'integrity constraint')
            || str_contains($lower, 'stack trace')
            || str_contains($message, 'Illuminate\\')
            || str_contains($message, 'PDOException');
    }

    public static function fallback(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);
        if (self::isInternal($msg) || strlen($msg) > 160) {
            if (str_contains($lower, 'wallet') || str_contains($lower, 'ledger') || str_contains($lower, 'commission')) {
                return 'Your payment is being confirmed. Open My Trips to see this booking.';
            }

            return 'Could not complete this request. Please try again.';
        }

        return $msg !== '' ? $msg : 'Could not complete this request. Please try again.';
    }
}
