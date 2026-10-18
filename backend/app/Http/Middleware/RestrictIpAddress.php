<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictIpAddress
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('famie.ip_restriction_enabled', true) === false) {
            return $next($request);
        }

        /** @var list<string> $allowedIps */
        $allowedIps = config('famie.allowed_ips', []);

        $clientIp = $request->ip();

        if (empty($allowedIps) || ! in_array($clientIp, $allowedIps, true)) {
            abort(403, __('errors.ip_forbidden'));
        }

        return $next($request);
    }
}
