<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\JwtToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        if ($token === '') {
            return response()->json(['message' => 'Missing bearer token', 'statusCode' => 401], 401);
        }
        try {
            $payload = JwtToken::decode($token);
        } catch (UnexpectedValueException) {
            return response()->json(['message' => 'Invalid token', 'statusCode' => 401], 401);
        }
        $user = User::query()->find($payload->sub ?? null);
        if (! $user) {
            return response()->json(['message' => 'Invalid token', 'statusCode' => 401], 401);
        }
        $request->attributes->set('jwt', $payload);
        $request->attributes->set('actor', $user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
