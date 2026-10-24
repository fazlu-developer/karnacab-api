<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CorsLiveProxyController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        if (! app()->environment('local')) {
            abort(404);
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->withCors($request, response('', 204));
        }

        $target = 'https://api.karnaride.in/'.ltrim($path, '/');
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $target .= '?'.$query;
        }

        $headers = [];
        foreach (['Authorization', 'Accept', 'Accept-Language', 'Content-Type'] as $name) {
            $value = $request->header($name);
            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        try {
            $pending = Http::timeout(25)->withHeaders($headers);
            $body = $request->getContent();
            if ($body !== '') {
                $pending = $pending->withBody(
                    $body,
                    $request->header('Content-Type', 'application/json'),
                );
            }
            $upstream = $pending->send($request->method(), $target);
            $response = response($upstream->body(), $upstream->status());
            $contentType = $upstream->header('Content-Type');
            if (is_string($contentType) && $contentType !== '') {
                $response->header('Content-Type', $contentType);
            }

            return $this->withCors($request, $response);
        } catch (Throwable $e) {
            return $this->withCors($request, response()->json([
                'message' => 'Cannot reach KarnaRide live API through the local Chrome proxy.',
                'detail' => $e->getMessage(),
            ], 502));
        }
    }

    private function withCors(Request $request, Response $response): Response
    {
        $origin = (string) $request->headers->get('Origin', '*');
        $response->headers->set('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Accept-Language, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
