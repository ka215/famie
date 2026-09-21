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
        $allowedIps = array_filter(array_map('trim', explode(',', (string) env('ALLOWED_IPS', ''))));

        $clientIp = $request->ip();

        if (empty($allowedIps) || ! in_array($clientIp, $allowedIps, true)) {
            abort(403, 'Access denied: Your IP address is not authorized.');
        }

        return $next($request);
    }
}
