<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $defaultLocale = app()->getLocale();

        app()->setLocale($request->user()?->preferredLocale() ?? $defaultLocale);

        try {
            return $next($request);
        } finally {
            app()->setLocale($defaultLocale);
        }
    }
}
