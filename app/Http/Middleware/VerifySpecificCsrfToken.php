<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;

class VerifySpecificCsrfToken extends BaseVerifier
{
    /**
     * List of endpoints that should enforce CSRF.
     *
     * @var array
     */
    protected $only = [
        '/api/sensitive-action',   // add your endpoints here
        '/api/another-protected',
    ];

    /**
     * Determine if the request has a URI that should be excluded.
     * We reverse it: only allow CSRF for listed routes.
     */
    protected function inExceptArray($request)
    {
        // If the route is NOT in $only, skip CSRF
        foreach ($this->only as $except) {
            if ($request->is($except)) {
                return false; // enforce CSRF
            }
        }
        return true; // skip CSRF
    }
}
