<?php

namespace App\Http\Middleware;

use App\Support\UiLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUiLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $phase = 'guest'): Response
    {
        $previous = app()->getLocale();
        $preference = $phase === 'authenticated' ? $request->user()?->ui_locale : null;
        $locale = $preference !== null
            ? (in_array($preference, UiLocale::supported(), true) ? $preference : config('i18n.fallback'))
            : UiLocale::fromHeader($request->header('Accept-Language'));
        if ($phase === 'guest' && array_key_exists('ui_locale', $request->all())) {
            $request->attributes->set('has_ui_locale_input', true);
            $request->attributes->set('ui_locale_input', $request->input('ui_locale'));
        }
        UiLocale::apply($locale);
        try {
            $response = $next($request);

            return UiLocale::decorate($response, $request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
