<?php

namespace App\Http\Controllers\Api\v1\Rag;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class RagController extends ApiController
{

    private string $pythonBaseUrl;

    public function __construct()
    {
        $this->pythonBaseUrl = config('services.python.base_url', 'http://localhost:8001');
    }

    public function ask(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:2000',
            'session_id' => 'required|string',
            'msg_id' => 'nullable|string',
            'selected_text' => 'nullable|string',

        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        try {
            $response = Http::withToken($request->bearerToken())
                ->timeout(60)
                ->post($this->pythonBaseUrl . '/api/v1/chat/ask', [
                    'query'         => $validated['query'],
                    'session_id'    => $validated['session_id'],
                    'msg_id'        => $validated['msg_id'] ?? null,
                    'selected_text' => $validated['selected_text'] ?? null,
                ]);

            if ($response->failed()) {
                Log::error('Python Ask Failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return $this->errorResponse(
                    'خطا در ارتباط با سرویس هوش مصنوعی',
                    500,
                    [$response->json(), $response->status()]);
            }
            return $this->successResponse(response()->json($response->json()), 200);

        } catch (\Exception $e) {
            Log::error('RAG Ask Exception: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'خطا در پردازش درخواست',
                'errors'  => $e->getMessage(),
            ], 500);
        }
    }

    public function askWithFile()
    {

    }
}
