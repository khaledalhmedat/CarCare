<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ?string $role = null)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً',
            ], 401);
        }

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم تحديد الدور المطلوب للوصول إلى هذا القسم',
            ], 403);
        }

        if (!$request->user()->hasRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول إلى هذا القسم',
            ], 403);
        }

        return $next($request);
    }
}
