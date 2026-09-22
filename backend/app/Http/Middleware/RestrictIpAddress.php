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
        /** @var list<string> $allowedIps */
        $allowedIps = config('famie.allowed_ips', []);

        $clientIp = $request->ip();

        if (empty($allowedIps) || ! in_array($clientIp, $allowedIps, true)) {
            abort(403, 'Access denied: Your IP address is not authorized.');
        }

        return $next($request);
    }
}
