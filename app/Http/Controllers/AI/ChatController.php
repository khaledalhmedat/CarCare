<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    
    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $user = $request->user();

        $response = Http::post(config('services.ai_service.url') . '/chat', [
            'user_id' => $user->id,
            'message' => $validated['message'],
        ]);

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'فشل الاتصال بالمساعد الذكي'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $response->json()
        ]);
    }


    public function history(Request $request)
    {
        $user = $request->user();

        $response = Http::get(config('services.ai_service.url') . '/chat/history/' . $user->id);

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'فشل جلب سجل المحادثة'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $response->json()
        ]);
    }

    
    public function deleteHistory(Request $request)
    {
        $user = $request->user();

        $response = Http::delete(config('services.ai_service.url') . '/chat/history/' . $user->id);

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'فشل حذف سجل المحادثة'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حذف سجل المحادثة بنجاح'
        ]);
    }
}