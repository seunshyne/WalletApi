<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check() || !auth()->user()->isAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Admin access only.'
            ], 403);
        }

        return $next($request);
    }
}