<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestContext
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('request_id', (string) Str::uuid());
        $response = $next($request);
        $response->headers->set('X-Request-Id', $request->attributes->get('request_id'));
        return $response;
    }
}
