<?php

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

class JwtToken
{
    public static function encode(string $sub, string $role): string
    {
        $now = time();

        return JWT::encode([
            'sub' => $sub,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + (int) config('karnacab.jwt_ttl'),
        ], (string) config('app.jwt_secret'), 'HS256');
    }

    public static function decode(string $token): object
    {
        try {
            return JWT::decode($token, new Key((string) config('app.jwt_secret'), 'HS256'));
        } catch (\Throwable $e) {
            throw new UnexpectedValueException('Invalid token');
        }
    }
}
