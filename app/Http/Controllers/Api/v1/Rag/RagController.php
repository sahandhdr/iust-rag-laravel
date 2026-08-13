<?php

namespace App\Http\Controllers\Api\v1\Rag;

use App\Http\Controllers\Api\v1\ApiController;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatSession;
use App\Traits\v1\ApiInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RagController extends ApiController
{
    use ApiInfo;

    private string $pythonBaseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->pythonBaseUrl = rtrim(config('services.python.base_url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) config('services.python.timeout', 120);
    }

    public function ask(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query'         => 'required|string|max:2000',
            'session_id'    => 'required',
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
        $sessionId = $validated['session_id'];

        $sessionCheck = $this->authorizeSession($sessionId, $user->id);
        if ($sessionCheck !== true) {
            return $sessionCheck;
        }

        $humanMsgId = $validated['msg_id'] ?? (string) Str::uuid();

        $human = new ChatMessage();
        $human->session_id = $sessionId;
        $human->role = 'human';
        $human->content = $validated['query'];
        $human->msg_id = $humanMsgId;
        $human->sources = null;
        if (!$human->save()) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $payload = [
            'query'      => $validated['query'],
            'session_id' => (string) $sessionId,
            'msg_id'     => $humanMsgId,
            'user_context' => [
                'user_id'     => $info['user_id'],
                'username'    => $info['username'],
                'roles'       => $info['roles'],
                'departments' => $info['departments'],
                'permissions' => $info['permissions'],
            ],
        ];
        if (!empty($validated['selected_text'])) {
            $payload['selected_text'] = $validated['selected_text'];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->pythonBaseUrl . '/api/v1/chat/ask', $payload);

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
                        'human_message_id' => $human->id,
                    ]
                );
            }

            $body = $response->json() ?? [];
            $data = $body['data'] ?? $body;
            $answer = $data['answer'] ?? '';
            $sources = $data['sources'] ?? null;

            $ai = new ChatMessage();
            $ai->session_id = $sessionId;
            $ai->role = 'ai';
            $ai->content = is_string($answer) ? $answer : json_encode($answer, JSON_UNESCAPED_UNICODE);
            $ai->msg_id = (string) Str::uuid();
            $ai->sources = $sources;
            $ai->save();

            return $this->successResponse([
                'answer'            => $answer,
                'sources'           => $sources,
                'session_id'        => $sessionId,
                'processing_time'   => $data['processing_time'] ?? null,
                'human_message_id'  => $human->id,
                'ai_message_id'     => $ai->id,
                'msg_id'            => $humanMsgId,
            ], 200, 'rag-ok');
        } catch (\Throwable $e) {
            Log::error('RAG ask exception: ' . $e->getMessage(), ['exception' => $e]);
            return $this->errorResponse('rag-connection-error', 500, [
                'human_message_id' => $human->id,
            ]);
        }
    }

    public function askWithFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query'      => 'required|string|max:2000',
            'session_id' => 'required',
            'msg_id'     => 'nullable|string|max:50',
            'file'       => 'required|file|max:20480',
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

        $sessionId = $request->input('session_id');
        $sessionCheck = $this->authorizeSession($sessionId, $user->id);
        if ($sessionCheck !== true) {
            return $sessionCheck;
        }

        $humanMsgId = $request->input('msg_id') ?: (string) Str::uuid();
        $query = $request->input('query');

        $human = new ChatMessage();
        $human->session_id = $sessionId;
        $human->role = 'human';
        $human->content = $query;
        $human->msg_id = $humanMsgId;
        $human->sources = null;
        if (!$human->save()) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $userContextJson = json_encode([
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'roles'       => $info['roles'],
            'departments' => $info['departments'],
            'permissions' => $info['permissions'],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $file = $request->file('file');
            $response = Http::timeout($this->timeout)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )
                ->post($this->pythonBaseUrl . '/api/v1/chat/ask_with_file', [
                    'query'        => $query,
                    'session_id'   => (string) $sessionId,
                    'msg_id'       => $humanMsgId,
                    'user_context' => $userContextJson,
                ]);

            if ($response->failed()) {
                Log::error('Python RAG askWithFile failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return $this->errorResponse(
                    'python-rag-failed',
                    $response->status() >= 400 ? $response->status() : 500,
                    [
                        'python_status' => $response->status(),
                        'python_body'   => $response->json() ?? $response->body(),
                        'human_message_id' => $human->id,
                    ]
                );
            }

            $body = $response->json() ?? [];
            $data = $body['data'] ?? $body;
            $answer = $data['answer'] ?? '';
            $sources = $data['sources'] ?? null;

            $ai = new ChatMessage();
            $ai->session_id = $sessionId;
            $ai->role = 'ai';
            $ai->content = is_string($answer) ? $answer : json_encode($answer, JSON_UNESCAPED_UNICODE);
            $ai->msg_id = (string) Str::uuid();
            $ai->sources = $sources;
            $ai->save();

            return $this->successResponse([
                'answer'           => $answer,
                'sources'          => $sources,
                'session_id'       => $sessionId,
                'processing_time'  => $data['processing_time'] ?? null,
                'file_processed'   => $data['file_processed'] ?? $file->getClientOriginalName(),
                'human_message_id' => $human->id,
                'ai_message_id'    => $ai->id,
                'msg_id'           => $humanMsgId,
            ], 200, 'rag-ok');
        } catch (\Throwable $e) {
            Log::error('RAG askWithFile exception: ' . $e->getMessage(), ['exception' => $e]);
            return $this->errorResponse('rag-connection-error', 500, [
                'human_message_id' => $human->id,
            ]);
        }
    }

    /**
     * @return true|\Illuminate\Http\JsonResponse
     */
    private function authorizeSession($sessionId, int $userId)
    {
        $session = ChatSession::where('id', $sessionId)->first();
        if (!$session) {
            return $this->errorResponse('session-notFound', 404);
        }

        if (
            !Auth::user()->hasAnyRole(['admin', 'developer'])
            && (int) $session->user_id !== (int) $userId
        ) {
            return $this->errorResponse('user-notAuthorized', 403);
        }

        return true;
    }
}
