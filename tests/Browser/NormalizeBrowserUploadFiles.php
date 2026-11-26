<?php

namespace Tests\Browser;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pest's browser server keeps the multipart field name literal instead of parsing it as PHP does.
 */
class NormalizeBrowserUploadFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->files->has('files[]')) {
            $request->files->set('files', [$request->files->get('files[]')]);
        }

        return $next($request);
    }
}
