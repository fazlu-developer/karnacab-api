<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\JwtToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $tokenEpoch = (int) ($payload->sev ?? 0);
        $userEpoch = (int) ($user->session_epoch ?? 0);
        if ($tokenEpoch !== $userEpoch) {
            return response()->json([
                'message' => 'This account is signed in on another device.',
                'statusCode' => 401,
                'code' => 'SESSION_REVOKED',
            ], 401);
        }
        $jti = isset($payload->jti) ? (string) $payload->jti : '';
        if ($jti !== '' && Schema::hasTable('app_sessions')) {
            $session = DB::table('app_sessions')->where('jti', $jti)->where('user_id', $user->id)->first();
            if (! $session || $session->revoked_at !== null || $session->status !== 'active') {
                return response()->json([
                    'message' => 'This account is signed in on another device.',
                    'statusCode' => 401,
                    'code' => 'SESSION_REVOKED',
                ], 401);
            }
            DB::table('app_sessions')->where('id', $session->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        }
        $request->attributes->set('jwt', $payload);
        $request->attributes->set('actor', $user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
