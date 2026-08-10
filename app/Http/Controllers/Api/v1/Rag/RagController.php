<?php

namespace App\Http\Controllers\Api\v1\Rag;

use App\Http\Controllers\Api\v1\ApiController;
use App\Traits\v1\ApiInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RagController extends ApiController
{
    use ApiInfo;

    private string $pythonBaseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->pythonBaseUrl = rtrim(config('services.python.base_url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) config('services.python.timeout', 60);
    }

    public function ask(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query'         => 'required|string|max:2000',
            'session_id'    => 'required|string',
            'msg_id'        => 'nullable|string|max:50',
            'selected_text' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->messages(), 422);
        }

        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('unauthenticated', 401);
        }

        $info = $this->getUserAclInfo($user->id);
        if (isset($info['code']) && $info['code'] === 404) {
            return $this->errorResponse('user-notFound', 404);
        }
        if (empty($info['roles'])) {
            return $this->errorResponse('user-has-no-role', 403);
        }

        $validated = $validator->validated();

        $payload = [
            'query'      => $validated['query'],
            'session_id' => $validated['session_id'],
            'user_context' => [
                'user_id'     => $info['user_id'],
                'username'    => $info['username'],
                'roles'       => $info['roles'],
                'departments' => $info['departments'],
                'permissions' => $info['permissions'],
            ],
        ];

        if (!empty($validated['msg_id'])) {
            $payload['msg_id'] = $validated['msg_id'];
        }
        if (!empty($validated['selected_text'])) {
            $payload['selected_text'] = $validated['selected_text'];
        }

        try {
            $http = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson();

            // اختیاری؛ برای این مسیر دیگر لازم نیست Python به Laravel برگردد
            $token = $request->bearerToken();
            if ($token) {
                $http = $http->withToken($token);
            }

            $response = $http->post($this->pythonBaseUrl . '/api/v1/chat/ask', $payload);

            if ($response->failed()) {
                Log::error('Python RAG ask failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return $this->errorResponse(
                    'python-rag-failed',
                    $response->status() >= 400 ? $response->status() : 500,
                    [
                        'python_status' => $response->status(),
                        'python_body'   => $response->json() ?? $response->body(),
                    ]
                );
            }

            return $this->successResponse($response->json() ?? [], 200, 'rag-ok');
        } catch (\Throwable $e) {
            Log::error('RAG ask exception: ' . $e->getMessage(), ['exception' => $e]);
            return $this->errorResponse('rag-connection-error', 500);
        }
    }

    public function askWithFile(Request $request)
    {
        return $this->errorResponse('not-implemented', 501);
    }
}
