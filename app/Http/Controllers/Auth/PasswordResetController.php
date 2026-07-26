<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyResetOtpRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(protected PasswordResetService $service) {}

    /**
     * Always returns the same generic message — never reveals whether the email exists.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->requestOtp($request->validated()['email']);

        return response()->json([
            'success' => true,
            'message' => 'إذا كان هذا البريد الإلكتروني مسجلاً لدينا، فسيتم إرسال رمز إعادة التعيين إليه.',
        ]);
    }

    public function verifyResetOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        // business failures throw ValidationException -> standard 422 via the handler
        $resetToken = $this->service->verifyOtp($data['email'], $data['otp']);

        return response()->json([
            'success' => true,
            'message' => 'تم التحقق من الرمز بنجاح',
            'data' => [
                'reset_token' => $resetToken,
                'expires_in_minutes' => PasswordResetService::RESET_TOKEN_TTL_MINUTES,
            ],
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->service->resetPassword($data['email'], $data['reset_token'], $data['password']);

        return response()->json([
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح، يرجى تسجيل الدخول من جديد.',
        ]);
    }
}
