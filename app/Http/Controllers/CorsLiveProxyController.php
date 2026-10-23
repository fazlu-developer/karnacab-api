<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class CorsLiveProxyController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        if (! app()->environment('local')) {
            abort(404);
        }

        $target = 'https://api.karnaride.in/'.ltrim($path, '/');
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $target .= '?'.$query;
        }

        if ($request->isMethod('OPTIONS')) {
            return response('', 204);
        }

        $headers = [];
        foreach (['Authorization', 'Accept', 'Accept-Language', 'Content-Type'] as $name) {
            $value = $request->header($name);
            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

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

        return $response;
    }
}
