<?php

namespace App\Http\Controllers\Api\v1\Chat;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Chat\ChatMessageResource;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatSession;
use App\Traits\v1\Auditable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ChatMessageController extends ApiController
{
    use Auditable;

    public function indexBySession($session_id)
    {
        if (DB::table('chat_messages')->count() > 0) {
            if (Auth::user()->hasAnyRole(['admin', 'developer'])) {
                $messages = ChatMessage::where('session_id', $session_id)->get();

                return $this->successResponse(ChatMessageResource::collection($messages), 200);
            }

            $session = DB::table('chat_sessions')->where('id', $session_id)->first();
            if ($session && (int) $session->user_id === (int) Auth::id()) {
                $messages = ChatMessage::where(['session_id' => $session_id])->get();

                return $this->successResponse(ChatMessageResource::collection($messages), 200);
            }

            return $this->errorResponse('user-notAuthorized', 403);
        }

        return $this->errorResponse('no-chat', 404);
    }

    private function storeSession(string $title): array
    {
        $session = new ChatSession();
        $session->title = $title;
        $session->user_id = Auth::id();

        if (!$session->save()) {
            return ['status' => 'error', 'message' => 'insert-failed'];
        }

        return ['status' => 'success', 'message' => 'session-created', 'data' => $session];
    }

    public function storeMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content'    => 'required|string',
            'role'       => 'required|in:human,ai,system',
            'session_id' => 'nullable|integer',
            'sources'    => 'nullable|array',
            'msg_id'     => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->messages(), 422);
        }

        $sessionId = $request->session_id;

        if (!$sessionId) {
            $title = mb_substr($request->input('content'), 0, 80);
            $created = $this->storeSession($title);

            if ($created['status'] !== 'success') {
                return $this->errorResponse('session-failed', 500);
            }

            $sessionId = $created['data']->id;
        } else {
            $session = ChatSession::where('id', $sessionId)->first();

            if (!$session) {
                return $this->errorResponse('session-notFound', 404);
            }

            if (
                !Auth::user()->hasAnyRole(['admin', 'developer'])
                && (int) $session->user_id !== (int) Auth::id()
            ) {
                return $this->errorResponse('user-notAuthorized', 403);
            }
        }

        $message = new ChatMessage();
        $message->content = $request->input('content');
        $message->role = $request->role;
        $message->session_id = $sessionId;
        $message->msg_id = $request->msg_id ?: (string) Str::uuid();
        $message->sources = $request->role === 'ai' ? ($request->sources ?? null) : null;

        if (!$message->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        return $this->successResponse(new ChatMessageResource($message), 201, 'message-successfully-saved');
    }

    public function show($message_id)
    {
        if ($this->checkExistsMessageById($message_id)) {
            if (Auth::user()->hasAnyRole(['admin', 'developer'])) {
                $message = ChatMessage::where('id', $message_id)->first();

                return $this->successResponse(new ChatMessageResource($message), 200);
            }

            if ($this->authorizeMessageByUserId($message_id)['status'] === 'success') {
                $message = ChatMessage::where(['id' => $message_id])->first();

                return $this->successResponse(new ChatMessageResource($message), 200);
            }

            return $this->errorResponse('user-notAuthorized', 403);
        }

        return $this->errorResponse('message-notFound', 404);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'content'  => 'nullable|string',
            'feedback' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->messages(), 422);
        }

        if (!$this->checkExistsMessageById($id)) {
            return $this->errorResponse('message-notFound', 404);
        }

        $auth = $this->authorizeMessageByUserId($id);
        if (($auth['status'] ?? '') !== 'success' && !Auth::user()->hasAnyRole(['admin', 'developer'])) {
            return $this->errorResponse('user-notAuthorized', 403);
        }

        $message = ChatMessage::where(['id' => $id])->whereNull('deleted_at')->first();
        if (!$message) {
            return $this->errorResponse('message-notFound', 404);
        }

        if ($request->filled('content')) {
            $message->content = $request->input('content');
        }
        if ($request->has('feedback') && $request->feedback !== null && $request->feedback !== '') {
            $message->feedback = $request->feedback;
        }

        if (!$message->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        if ($request->has('feedback') && $request->feedback !== null && $request->feedback !== '') {
            $this->audit('chat.feedback', 'chat_message', $message->id, [
                'feedback'   => (string) $message->feedback,
                'session_id' => $message->session_id,
                'role'       => $message->role,
                'via'        => 'update',
            ]);
        }

        return $this->successResponse(new ChatMessageResource($message), 200, 'message-successfully-updated');
    }

    public function destroy($message_id)
    {
        if (!$this->checkExistsMessageById($message_id)) {
            return $this->errorResponse('message-notFound', 404);
        }

        if (Auth::user()->hasAnyRole(['admin', 'developer'])) {
            if (ChatMessage::where('id', $message_id)->delete()) {
                $this->audit('chat.message_delete', 'chat_message', $message_id, [
                    'by' => 'admin',
                ]);

                return $this->successResponse('', 200, 'delete-successful');
            }

            return $this->errorResponse('delete-failed', 500);
        }

        if (($this->authorizeMessageByUserId($message_id)['status'] ?? '') === 'success') {
            if (ChatMessage::where(['id' => $message_id])->delete()) {
                $this->audit('chat.message_delete', 'chat_message', $message_id, [
                    'by' => 'owner',
                ]);

                return $this->successResponse('', 200, 'delete-successful');
            }

            return $this->errorResponse('delete-failed', 500);
        }

        return $this->errorResponse('user-notAuthorized', 403);
    }

    public function setFeedbackOnMessage($message_id, $feedback)
    {
        if (!$this->checkExistsMessageById($message_id)) {
            return $this->errorResponse('message-notFound', 404);
        }

        if (($this->authorizeMessageByUserId($message_id)['status'] ?? '') !== 'success') {
            return $this->errorResponse('user-notAuthorized', 403);
        }

        $message = ChatMessage::where('id', $message_id)->first();
        if (!$message) {
            return $this->errorResponse('message-notFound', 404);
        }

        if ($message->role !== 'ai') {
            return $this->errorResponse('human-role', 403);
        }

        if ($feedback !== '0' && $feedback !== '1' && $feedback !== 0 && $feedback !== 1) {
            return $this->errorResponse('feedback-notExists', 404);
        }

        $message->feedback = (string) $feedback;
        if (!$message->save()) {
            return $this->errorResponse('update-failed', 500);
        }

        $this->audit('chat.feedback', 'chat_message', $message->id, [
            'feedback'   => (string) $message->feedback,
            'session_id' => $message->session_id,
            'role'       => 'ai',
            'via'        => 'setFeedbackOnMessage',
        ]);

        return $this->successResponse('', 200, 'update-successful');
    }

    public function search(Request $request)
    {
        if (
            $request->hasHeader('accept')
            && $request->header('accept') == 'application/json'
            && $request->ajax()
        ) {
            $validator = Validator::make($request->all(), [
                'content' => 'nullable',
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => 'validation-error', 'errors' => $validator->errors()]);
            }

            $query = ChatMessage::with('chat_session', 'files')->select('*');
            if ($request->input('content') != null) {
                $query->where('content', 'like', '%' . $request->input('content') . '%');
            }

            return $query->exists()
                ? $this->successResponse(ChatMessageResource::collection($query->get()), 200, 'message-found')
                : $this->errorResponse('message-notFound', 404);
        }

        return $this->errorResponse('refused', 500);
    }

    private function checkExistsMessageById($id)
    {
        return DB::table('chat_messages')->where('id', $id)->exists();
    }

    private function authorizeMessageByUserId($message_id)
    {
        if ($this->checkExistsMessageById($message_id)) {
            $session_id = DB::table('chat_messages')->where('id', $message_id)->value('session_id');
            $session = DB::table('chat_sessions')->where('id', $session_id)->first();

            if (!$session) {
                return ['status' => 'error', 'message' => 'session-notFound'];
            }

            if ((int) $session->user_id === (int) Auth::id()) {
                return ['status' => 'success', 'message' => 'authorized'];
            }

            return ['status' => 'error', 'message' => 'user-notAuthorized'];
        }

        return ['status' => 'error', 'message' => 'message-notFound'];
    }
}
