<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckCarWasherRole
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->hasRole('car-washer')) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك. هذا القسم مخصص لأصحاب المغاسل فقط'
            ], 403);
        }
        
        return $next($request);
    }
}