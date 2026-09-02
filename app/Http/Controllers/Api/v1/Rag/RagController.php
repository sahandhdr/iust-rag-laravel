<?php

namespace App\Http\Controllers\Api\v1\Rag;

use App\Http\Controllers\Api\v1\ApiController;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatMessageFile;
use App\Models\Chat\ChatSession;
use App\Services\RagResponseCache\RagResponseCache;
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

/**
 * Laravel gateway for RAG:
 * - ask / askStream / askWithFile
 * - MySQL history (append-only human + ai; edit keeps previous rows)
 * - exact response cache via Redis (RagResponseCache)
 * - user_context ACL proxy to Python
 */
class RagController extends ApiController
{
    use ApiInfo, Auditable;

    private string $pythonBaseUrl;
    private int $timeout;
    private RagResponseCache $responseCache;

    public function __construct()
    {
        $this->pythonBaseUrl = rtrim(config('services.python.base_url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) config('services.python.timeout', 180);
        $this->responseCache = new RagResponseCache();
    }

    public function ask(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query'               => 'required|string|max:2000',
            'session_id'          => 'nullable',
            'msg_id'              => 'nullable|string|max:50',
            'selected_text'       => 'nullable|string',
            'edit_of_message_id'  => 'nullable|integer|min:1',
            'skip_cache'          => 'nullable|boolean',
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
        $skipCache = (bool) ($validated['skip_cache'] ?? false);

        [$sessionId, $sessionOk] = $this->resolveOrCreateSession(
            $validated['session_id'] ?? null,
            $user->id,
            $validated['query']
        );
        if ($sessionOk !== true) {
            return $sessionOk;
        }

        $editOfId = $validated['edit_of_message_id'] ?? null;
        if ($editOfId !== null) {
            $editCheck = $this->authorizeEditOfMessage((int) $editOfId, (int) $sessionId, (int) $user->id);
            if ($editCheck !== true) {
                return $editCheck;
            }
        }

        // --- exact cache (same query + same ACL generation) ---
        if (!$skipCache) {
            $cached = $this->responseCache->get($validated['query'], $info);
            if (is_array($cached) && array_key_exists('answer', $cached)) {
                $human = $this->persistHumanMessage($sessionId, $validated['query'], $editOfId);
                if ($human === null) {
                    return $this->errorResponse('human-message-save-failed', 500);
                }

                $ai = new ChatMessage();
                $ai->session_id = $sessionId;
                $ai->role = 'ai';
                $ai->content = is_string($cached['answer'])
                    ? $cached['answer']
                    : json_encode($cached['answer'], JSON_UNESCAPED_UNICODE);
                $ai->msg_id = null;
                $ai->sources = $cached['sources'] ?? null;

                if (!$ai->save()) {
                    return $this->errorResponse('ai-message-save-failed', 500, [
                        'human_message_id' => $human->id,
                    ]);
                }

                $this->audit('rag.ask', 'chat_session', $sessionId, [
                    'status'                 => 'ok',
                    'from_cache'             => true,
                    'human_message_id'       => $human->id,
                    'ai_message_id'          => $ai->id,
                    'edited_from_message_id' => $editOfId,
                ]);

                return $this->successResponse([
                    'answer'                 => $cached['answer'],
                    'sources'                => $cached['sources'] ?? null,
                    'session_id'             => $sessionId,
                    'processing_time'        => 0,
                    'from_cache'             => true,
                    'human_message_id'       => $human->id,
                    'ai_message_id'          => $ai->id,
                    'edited_from_message_id' => $editOfId,
                ], 200, 'rag-ok-cache');
            }
        }

        $human = $this->persistHumanMessage($sessionId, $validated['query'], $editOfId);
        if ($human === null) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $payload = [
            'query'        => $validated['query'],
            'session_id'   => (string) $sessionId,
            'user_context' => [
                'user_id'     => $info['user_id'],
                'username'    => $info['username'] ?? null,
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
                ->connectTimeout(30)
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

            $this->responseCache->set($validated['query'], $info, [
                'answer'  => $answer,
                'sources' => $sources,
            ]);

            $this->audit('rag.ask', 'chat_session', $sessionId, [
                'status'                 => 'ok',
                'from_cache'             => false,
                'human_message_id'       => $human->id,
                'ai_message_id'          => $ai->id,
                'edited_from_message_id' => $editOfId,
            ]);

            return $this->successResponse([
                'answer'                 => $answer,
                'sources'                => $sources,
                'session_id'             => $sessionId,
                'processing_time'        => $data['processing_time'] ?? null,
                'from_cache'             => false,
                'human_message_id'       => $human->id,
                'ai_message_id'          => $ai->id,
                'edited_from_message_id' => $editOfId,
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

    public function askStream(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query'              => 'required|string|max:2000',
            'session_id'         => 'nullable',
            'selected_text'      => 'nullable|string',
            'edit_of_message_id' => 'nullable|integer|min:1',
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

        [$sessionId, $sessionOk] = $this->resolveOrCreateSession(
            $validated['session_id'] ?? null,
            $user->id,
            $validated['query']
        );
        if ($sessionOk !== true) {
            return $sessionOk;
        }

        $editOfId = $validated['edit_of_message_id'] ?? null;
        if ($editOfId !== null) {
            $editCheck = $this->authorizeEditOfMessage((int) $editOfId, (int) $sessionId, (int) $user->id);
            if ($editCheck !== true) {
                return $editCheck;
            }
        }

        $human = $this->persistHumanMessage($sessionId, $validated['query'], $editOfId);
        if ($human === null) {
            return $this->errorResponse('human-message-save-failed', 500);
        }

        $payload = [
            'query'        => $validated['query'],
            'session_id'   => (string) $sessionId,
            'user_context' => [
                'user_id'     => $info['user_id'],
                'username'    => $info['username'] ?? null,
                'roles'       => $info['roles'],
                'departments' => $info['departments'],
                'permissions' => $info['permissions'],
            ],
        ];
        if (!empty($validated['selected_text'])) {
            $payload['selected_text'] = $validated['selected_text'];
        }

        $pythonUrl = $this->pythonBaseUrl . '/api/v1/chat/ask/stream';
        $timeout = max($this->timeout, 180);
        $humanId = $human->id;
        $controller = $this;
        $cache = $this->responseCache;
        $queryForCache = $validated['query'];
        $aclForCache = $info;

        return response()->stream(function () use (
            $payload,
            $pythonUrl,
            $timeout,
            $sessionId,
            $humanId,
            $controller,
            $cache,
            $queryForCache,
            $aclForCache,
            $editOfId
        ) {
            echo "event: meta\n";
            echo 'data: ' . json_encode([
                    'session_id'             => (string) $sessionId,
                    'human_message_id'       => $humanId,
                    'gateway'                => 'laravel',
                    'edited_from_message_id' => $editOfId,
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
                    ->connectTimeout(30)
                    ->withHeaders([
                        'Accept'           => 'text/event-stream',
                        'Content-Type'     => 'application/json',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->withOptions([
                        'stream'       => true,
                        'read_timeout' => $timeout,
                    ])
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

                                    $cache->set($queryForCache, $aclForCache, [
                                        'answer'  => $answer,
                                        'sources' => $sourcesBuffer,
                                    ]);

                                    $controller->audit('rag.ask_stream', 'chat_session', $sessionId, [
                                        'status'                 => 'ok',
                                        'human_message_id'       => $humanId,
                                        'ai_message_id'          => $ai->id,
                                        'edited_from_message_id' => $editOfId,
                                    ]);

                                    echo "event: persisted\n";
                                    echo 'data: ' . json_encode([
                                            'ai_message_id'          => $ai->id,
                                            'human_message_id'       => $humanId,
                                            'edited_from_message_id' => $editOfId,
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
            'query'              => 'required|string|max:2000',
            'session_id'         => 'nullable',
            'file'               => 'required|file|max:20480',
            'edit_of_message_id' => 'nullable|integer|min:1',
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

        $query = $request->input('query');
        $uploaded = $request->file('file');
        $editOfId = $request->input('edit_of_message_id');

        [$sessionId, $sessionOk] = $this->resolveOrCreateSession(
            $request->input('session_id'),
            $user->id,
            $query
        );
        if ($sessionOk !== true) {
            return $sessionOk;
        }

        if ($editOfId !== null) {
            $editCheck = $this->authorizeEditOfMessage((int) $editOfId, (int) $sessionId, (int) $user->id);
            if ($editCheck !== true) {
                return $editCheck;
            }
        }

        $human = $this->persistHumanMessage($sessionId, $query, $editOfId ? (int) $editOfId : null);
        if ($human === null) {
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
            'username'    => $info['username'] ?? null,
            'roles'       => $info['roles'],
            'departments' => $info['departments'],
            'permissions' => $info['permissions'],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $fileBinary = Storage::disk('public')->get($relativePath);
            $attachName = $messageFile->file_name;

            $response = Http::timeout($this->timeout)
                ->connectTimeout(30)
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
                'edited_from_message_id' => $editOfId,
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
                'edited_from_message_id' => $editOfId,
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

    private function persistHumanMessage($sessionId, string $content, ?int $editOfMessageId = null): ?ChatMessage
    {
        $human = new ChatMessage();
        $human->session_id = $sessionId;
        $human->role = 'human';
        $human->content = $content;
        $human->msg_id = null;
        $human->sources = null;

        // append-only: previous human/ai rows stay in DB
        if (!$human->save()) {
            return null;
        }

        if ($editOfMessageId !== null) {
            $this->audit('chat.message_edit', 'chat_message', $human->id, [
                'edited_from_message_id' => $editOfMessageId,
                'session_id'             => $sessionId,
            ]);
        }

        return $human;
    }

    /**
     * @return true|\Illuminate\Http\JsonResponse
     */
    private function authorizeEditOfMessage(int $messageId, int $sessionId, int $userId)
    {
        $msg = ChatMessage::where('id', $messageId)->first();
        if (!$msg || (int) $msg->session_id !== $sessionId) {
            return $this->errorResponse('edit-source-message-notFound', 404);
        }

        if ($msg->role !== 'human') {
            return $this->errorResponse('edit-source-must-be-human', 422);
        }

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

    /**
     * @return array{0: int|string|null, 1: true|\Illuminate\Http\JsonResponse}
     */
    private function resolveOrCreateSession($sessionId, int $userId, ?string $query = null): array
    {
        if ($sessionId !== null && $sessionId !== '') {
            $check = $this->authorizeSession($sessionId, $userId);
            if ($check !== true) {
                return [null, $check];
            }

            return [$sessionId, true];
        }

        $session = new ChatSession();
        $session->user_id = $userId;
        $title = $query ? mb_substr(trim($query), 0, 30) : null;
        if ($title !== null && $title !== '') {
            $session->title = $title;
        }

        if (!$session->save()) {
            return [null, $this->errorResponse('session-create-failed', 500)];
        }

        $this->audit('chat.session_auto_create', 'chat_session', $session->id, [
            'user_id' => $userId,
            'title'   => $session->title,
        ]);

        return [$session->id, true];
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
