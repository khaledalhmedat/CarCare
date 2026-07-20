<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return $this->renderApiException($e);
        }

        return parent::render($request, $e);
    }

    /**
     * Force a consistent JSON error shape for every /api/* request, regardless
     * of the client's Accept header (fixes 500 HTML / route('login') redirects
     * when Postman-style clients omit Accept: application/json).
     */
    protected function renderApiException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الإدخال غير صحيحة',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً',
            ], 401);
        }

        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول إلى هذا القسم',
            ], 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'العنصر غير موجود',
            ], 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: ($status === 404
                ? 'الرابط المطلوب غير موجود'
                : 'حدث خطأ أثناء معالجة الطلب');

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ غير متوقع',
        ], 500);
    }
}
