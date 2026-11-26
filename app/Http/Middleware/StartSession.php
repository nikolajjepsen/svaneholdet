<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as LaravelStartSession;

class StartSession extends LaravelStartSession
{
    protected function storeCurrentUrl(Request $request, $session): void
    {
        if (! $request->routeIs('login.confirm')) {
            parent::storeCurrentUrl($request, $session);
        }
    }
}
