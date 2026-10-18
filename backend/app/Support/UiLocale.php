<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UiLocale
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(config('i18n.locales'));
    }

    public static function fromTag(?string $tag): string
    {
        $fallback = config('i18n.fallback');
        if (! is_string($tag) || ! preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/D', $tag)) {
            return $fallback;
        }
        $tag = strtolower($tag);
        foreach (self::supported() as $locale) {
            if (strtolower($locale) === $tag) {
                return $locale;
            }
        }
        $language = explode('-', $tag)[0];
        foreach (self::supported() as $locale) {
            if (strtolower($locale) === $language) {
                return $locale;
            }
        }

        return $fallback;
    }

    public static function fromHeader(?string $header): string
    {
        $candidates = [];
        foreach (explode(',', $header ?? '') as $index => $part) {
            if (preg_match('/^\s*([a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*)(?:\s*;\s*q=(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\s*$/D', $part, $matches)) {
                $quality = isset($matches[2]) ? (float) $matches[2] : 1.0;
                if ($quality > 0) {
                    $candidates[] = ['tag' => $matches[1], 'quality' => $quality, 'index' => $index];
                }
            }
        }
        usort($candidates, fn (array $a, array $b): int => ($b['quality'] <=> $a['quality']) ?: ($a['index'] <=> $b['index']));

        return self::fromTag($candidates[0]['tag'] ?? null);
    }

    public static function apply(string $locale): void
    {
        app()->setLocale(config('i18n.locales.'.$locale.'.translation', config('i18n.fallback')));
        app('translator')->setFallback(config('i18n.locales.'.config('i18n.fallback').'.translation'));
        request()->attributes->set('ui_locale', $locale);
    }

    public static function decorate(Response $response, Request $request, bool $translatedException = false): Response
    {
        if ($translatedException || preg_match('#^(?:api/)?v1/(?:auth/.*|groups/[^/]+(?:/(?:members|categories|security-alerts)(?:/.*)?)?)/?$#D', $request->path())) {
            $response->headers->set('Content-Language', $request->attributes->get('ui_locale', self::fromHeader($request->header('Accept-Language'))));
            $response->setVary('Accept-Language', false);
        }
        if ($request->is('v1/auth/*') || $request->bearerToken() || $request->user()) {
            $response->headers->removeCacheControlDirective('public');
            $response->headers->removeCacheControlDirective('s-maxage');
            $response->headers->addCacheControlDirective('private');
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }
}
