<?php

namespace App\Http\Controllers\Api\v1\Rag;

use App\Http\Controllers\Api\v1\ApiController;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageFile;
use App\Models\Chat\ChatSession;
use App\Traits\v1\ApiInfo;
use App\Traits\v1\Auditable;
use App\Utility\FileManagerRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RagController extends ApiController
{
    use ApiInfo, Auditable;

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

        $human = new ChatMessage();
        $human->session_id = $sessionId;
        $human->role = 'human';
        $human->content = $validated['query'];
        $human->msg_id = null;
        $human->sources = null;
        if (!$human->save()) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $payload = [
            'query'      => $validated['query'],
            'session_id' => (string) $sessionId,
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

                $this->audit('rag.ask', 'chat_session', $sessionId, [
                    'status'           => 'failed',
                    'reason'           => 'python-rag-failed',
                    'python_status'    => $response->status(),
                    'human_message_id' => $human->id,
                ]);

                return $this->errorResponse(
                    'python-rag-failed',
                    $response->status() >= 400 ? $response->status() : 500,
                    [
                        'python_status'    => $response->status(),
                        'python_body'      => $response->json() ?? $response->body(),
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
            $ai->msg_id = null;
            $ai->sources = $sources;

            if (!$ai->save()) {
                $this->audit('rag.ask', 'chat_session', $sessionId, [
                    'status'           => 'failed',
                    'reason'           => 'ai-message-save-failed',
                    'human_message_id' => $human->id,
                ]);

                return $this->errorResponse('ai-message-save-failed', 500, [
                    'human_message_id' => $human->id,
                ]);
            }

            $this->audit('rag.ask', 'chat_session', $sessionId, [
                'status'           => 'ok',
                'human_message_id' => $human->id,
                'ai_message_id'    => $ai->id,
            ]);

            return $this->successResponse([
                'answer'           => $answer,
                'sources'          => $sources,
                'session_id'       => $sessionId,
                'processing_time'  => $data['processing_time'] ?? null,
                'human_message_id' => $human->id,
                'ai_message_id'    => $ai->id,
            ], 200, 'rag-ok');
        } catch (\Throwable $e) {
            Log::error('RAG ask exception: ' . $e->getMessage(), ['exception' => $e]);

            $this->audit('rag.ask', 'chat_session', $sessionId, [
                'status'           => 'failed',
                'reason'           => 'rag-connection-error',
                'human_message_id' => $human->id,
                'error'            => $e->getMessage(),
            ]);

            return $this->errorResponse('rag-connection-error', 500, [
                'human_message_id' => $human->id,
            ]);
        }
    }

    /**
     * SSE proxy → Python /api/v1/chat/ask/stream
     */
    public function askStream(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query'         => 'required|string|max:2000',
            'session_id'    => 'required',
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

        $human = new ChatMessage();
        $human->session_id = $sessionId;
        $human->role = 'human';
        $human->content = $validated['query'];
        $human->msg_id = null;
        $human->sources = null;
        if (!$human->save()) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $payload = [
            'query'      => $validated['query'],
            'session_id' => (string) $sessionId,
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

        $pythonUrl = $this->pythonBaseUrl . '/api/v1/chat/ask/stream';
        $timeout = $this->timeout;
        $humanId = $human->id;
        $controller = $this;

        return response()->stream(function () use ($payload, $pythonUrl, $timeout, $sessionId, $humanId, $controller) {
            echo "event: meta\n";
            echo 'data: ' . json_encode([
                    'session_id'       => (string) $sessionId,
                    'human_message_id' => $humanId,
                    'gateway'          => 'laravel',
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();

            $sourcesBuffer = null;
            $currentEvent = null;
            $dataLines = [];
            $persisted = false;

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Accept'       => 'text/event-stream',
                        'Content-Type' => 'application/json',
                    ])
                    ->withOptions(['stream' => true])
                    ->post($pythonUrl, $payload);

                if ($response->failed()) {
                    $controller->audit('rag.ask_stream', 'chat_session', $sessionId, [
                        'status'           => 'failed',
                        'reason'           => 'python-rag-failed',
                        'python_status'    => $response->status(),
                        'human_message_id' => $humanId,
                    ]);

                    echo "event: error\n";
                    echo 'data: ' . json_encode([
                            'message' => 'python-rag-failed',
                            'status'  => $response->status(),
                        ], JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();

                    return;
                }

                $body = $response->toPsrResponse()->getBody();
                $buffer = '';

                while (!$body->eof()) {
                    $chunk = $body->read(1024);
                    if ($chunk === '' || $chunk === false) {
                        usleep(10000);
                        continue;
                    }

                    echo $chunk;
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();

                    $buffer .= $chunk;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        $line = rtrim($line, "\r");

                        if (str_starts_with($line, 'event:')) {
                            $currentEvent = trim(substr($line, 6));
                            $dataLines = [];
                        } elseif (str_starts_with($line, 'data:')) {
                            $dataLines[] = ltrim(substr($line, 5));
                        } elseif ($line === '' && $currentEvent !== null) {
                            $dataRaw = implode("\n", $dataLines);
                            $decoded = json_decode($dataRaw, true);

                            if ($currentEvent === 'sources' && is_array($decoded)) {
                                $sourcesBuffer = $decoded;
                            }

                            if ($currentEvent === 'done' && is_array($decoded) && !$persisted) {
                                $answer = $decoded['answer'] ?? '';
                                try {
                                    $ai = new ChatMessage();
                                    $ai->session_id = $sessionId;
                                    $ai->role = 'ai';
                                    $ai->content = is_string($answer)
                                        ? $answer
                                        : json_encode($answer, JSON_UNESCAPED_UNICODE);
                                    $ai->msg_id = null;
                                    $ai->sources = $sourcesBuffer;
                                    $ai->save();

                                    $persisted = true;

                                    $controller->audit('rag.ask_stream', 'chat_session', $sessionId, [
                                        'status'           => 'ok',
                                        'human_message_id' => $humanId,
                                        'ai_message_id'    => $ai->id,
                                    ]);

                                    echo "event: persisted\n";
                                    echo 'data: ' . json_encode([
                                            'ai_message_id'    => $ai->id,
                                            'human_message_id' => $humanId,
                                        ], JSON_UNESCAPED_UNICODE) . "\n\n";
                                    if (function_exists('ob_flush')) {
                                        @ob_flush();
                                    }
                                    flush();
                                } catch (\Throwable $e) {
                                    Log::error('RAG stream AI persist failed: ' . $e->getMessage());
                                    $controller->audit('rag.ask_stream', 'chat_session', $sessionId, [
                                        'status'           => 'failed',
                                        'reason'           => 'ai-persist-failed',
                                        'human_message_id' => $humanId,
                                        'error'            => $e->getMessage(),
                                    ]);
                                }
                            }

                            if ($currentEvent === 'error') {
                                $controller->audit('rag.ask_stream', 'chat_session', $sessionId, [
                                    'status'           => 'failed',
                                    'reason'           => 'python-stream-error',
                                    'human_message_id' => $humanId,
                                    'detail'           => $decoded,
                                ]);
                            }

                            $currentEvent = null;
                            $dataLines = [];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('RAG askStream exception: ' . $e->getMessage(), ['exception' => $e]);

                $controller->audit('rag.ask_stream', 'chat_session', $sessionId, [
                    'status'           => 'failed',
                    'reason'           => 'rag-connection-error',
                    'human_message_id' => $humanId,
                    'error'            => $e->getMessage(),
                ]);

                echo "event: error\n";
                echo 'data: ' . json_encode([
                        'message' => 'rag-connection-error',
                        'detail'  => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function askWithFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query'      => 'required|string|max:2000',
            'session_id' => 'required',
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

        $query = $request->input('query');
        $uploaded = $request->file('file');

        $human = new ChatMessage();
        $human->session_id = $sessionId;
        $human->role = 'human';
        $human->content = $query;
        $human->msg_id = null;
        $human->sources = null;

        if (!$human->save()) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $dir = 'messages/' . $human->id . '/files';
        $fileManager = new FileManagerRepo();
        $insertResult = $fileManager->insertFile($uploaded, $dir, 'public');

        if (!isset($insertResult['status']) || $insertResult['status'] !== 'ok') {
            $this->audit('rag.ask_with_file', 'chat_session', $sessionId, [
                'status'           => 'failed',
                'reason'           => 'file-upload-failed',
                'human_message_id' => $human->id,
            ]);

            return $this->errorResponse('file-upload-failed', 500, [
                'human_message_id' => $human->id,
            ]);
        }

        $relativePath = rtrim($insertResult['path'], '/') . '/' . $insertResult['filename'];

        $messageFile = new ChatMessageFile();
        $messageFile->path = $relativePath;
        $messageFile->extension = $insertResult['extension'] ?? $uploaded->getClientOriginalExtension();
        $messageFile->file_name = $insertResult['originalfilename'] ?? $uploaded->getClientOriginalName();
        $messageFile->message_id = $human->id;

        if (!$messageFile->save()) {
            $fileManager->removeFileFromStorage($relativePath, 'public');

            $this->audit('rag.ask_with_file', 'chat_session', $sessionId, [
                'status'           => 'failed',
                'reason'           => 'file-record-save-failed',
                'human_message_id' => $human->id,
            ]);

            return $this->errorResponse('file-record-save-failed', 500, [
                'human_message_id' => $human->id,
            ]);
        }

        $userContextJson = json_encode([
            'user_id'     => $info['user_id'],
            'username'    => $info['username'],
            'roles'       => $info['roles'],
            'departments' => $info['departments'],
            'permissions' => $info['permissions'],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $fileBinary = Storage::disk('public')->get($relativePath);
            $attachName = $messageFile->file_name;

            $response = Http::timeout($this->timeout)
                ->attach('file', $fileBinary, $attachName)
                ->post($this->pythonBaseUrl . '/api/v1/chat/ask_with_file', [
                    'query'        => $query,
                    'session_id'   => (string) $sessionId,
                    'user_context' => $userContextJson,
                ]);

            if ($response->failed()) {
                Log::error('Python RAG askWithFile failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                $this->audit('rag.ask_with_file', 'chat_session', $sessionId, [
                    'status'               => 'failed',
                    'reason'               => 'python-rag-failed',
                    'python_status'        => $response->status(),
                    'human_message_id'     => $human->id,
                    'chat_message_file_id' => $messageFile->id,
                ]);

                return $this->errorResponse(
                    'python-rag-failed',
                    $response->status() >= 400 ? $response->status() : 500,
                    [
                        'python_status'        => $response->status(),
                        'python_body'          => $response->json() ?? $response->body(),
                        'human_message_id'     => $human->id,
                        'chat_message_file_id' => $messageFile->id,
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
            $ai->msg_id = null;
            $ai->sources = $sources;

            if (!$ai->save()) {
                $this->audit('rag.ask_with_file', 'chat_session', $sessionId, [
                    'status'               => 'failed',
                    'reason'               => 'ai-message-save-failed',
                    'human_message_id'     => $human->id,
                    'chat_message_file_id' => $messageFile->id,
                ]);

                return $this->errorResponse('ai-message-save-failed', 500, [
                    'human_message_id'     => $human->id,
                    'chat_message_file_id' => $messageFile->id,
                    'answer'               => $answer,
                ]);
            }

            $this->audit('rag.ask_with_file', 'chat_session', $sessionId, [
                'status'               => 'ok',
                'human_message_id'     => $human->id,
                'ai_message_id'        => $ai->id,
                'chat_message_file_id' => $messageFile->id,
                'file_name'            => $messageFile->file_name,
            ]);

            return $this->successResponse([
                'answer'               => $answer,
                'sources'              => $sources,
                'session_id'           => $sessionId,
                'processing_time'      => $data['processing_time'] ?? null,
                'file_processed'       => $data['file_processed'] ?? $messageFile->file_name,
                'human_message_id'     => $human->id,
                'ai_message_id'        => $ai->id,
                'chat_message_file_id' => $messageFile->id,
                'file'                 => [
                    'id'         => $messageFile->id,
                    'file_name'  => $messageFile->file_name,
                    'extension'  => $messageFile->extension,
                    'path'       => $messageFile->path,
                    'message_id' => $messageFile->message_id,
                ],
            ], 200, 'rag-ok');
        } catch (\Throwable $e) {
            Log::error('RAG askWithFile exception: ' . $e->getMessage(), ['exception' => $e]);

            $this->audit('rag.ask_with_file', 'chat_session', $sessionId, [
                'status'               => 'failed',
                'reason'               => 'rag-connection-error',
                'human_message_id'     => $human->id,
                'chat_message_file_id' => $messageFile->id ?? null,
                'error'                => $e->getMessage(),
            ]);

            return $this->errorResponse('rag-connection-error', 500, [
                'human_message_id'     => $human->id,
                'chat_message_file_id' => $messageFile->id ?? null,
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
