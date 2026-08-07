<?php

namespace App\Http\Controllers\Api\v1\Chat;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Chat\ChatMessageResource;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChatMessageController extends ApiController
{
    public function indexBySession($session_id)
    {
        if (DB::table('chat_messages')->count()>0)
        {
            if (Auth::user()->hasAnyRole(['admin', 'developer']))
            {
                $messages = ChatMessage::where('session_id',$session_id)->get();
                return $this->successResponse(ChatMessageResource::collection($messages), 200);
            }
            else
            {
                $session = DB::table('chat_sessions')->where('id', $session_id)->first();
                if ($session && $session->user_id == Auth::id())
                {
                    $messages = ChatMessage::where(['session_id' => $session_id])->get();
                    return $this->successResponse(ChatMessageResource::collection($messages), 200);
                }
                return $this->errorResponse('user-notAuthorized', 403);
            }
        }
        return $this->errorResponse('no-chat', 404);
    }

    private function storeSession(string $title): array
    {
        $session = new ChatSession();
        $session->title = $title;
        $session->user_id = Auth::id();

        if (!$session->save())
            return ['status' => 'error', 'message' => 'insert-failed'];

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
                && $session->user_id != Auth::id()
            ) {
                return $this->errorResponse('user-notAuthorized', 403);
            }
        }

        $message = new ChatMessage();
        $message->content = $request->input('content');
        $message->role = $request->role;
        $message->session_id = $sessionId;
        $message->msg_id = $request->msg_id ?: (string) \Illuminate\Support\Str::uuid();
        $message->sources = $request->role === 'ai' ? ($request->sources ?? null) : null;

        if (!$message->save()) {
            return $this->errorResponse('save-failed', 500);
        }

        return $this->successResponse(new ChatMessageResource($message), 201, 'message-successfully-saved');
    }

    public function show($message_id)
    {
        if ($this->checkExistsMessageById($message_id))
        {
            if (Auth::user()->hasAnyRole(['admin', 'developer']))
            {
                $message = ChatMessage::where('id',$message_id)->first();
                return $this->successResponse(new ChatMessageResource($message), 200);
            }
            else
            {
                if ($this->authorizeMessageByUserId($message_id)['status'] == 'success')
                {
                    $message = ChatMessage::where(['id' => $message_id])->first();
                    return $this->successResponse(new ChatMessageResource($message), 200);
                }
                return $this->errorResponse('user-notAuthorized', 403);
            }
        }
        return $this->errorResponse('message-notFound', 404);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'content' => 'nullable|string',
            'feedback' => 'nullable|in:0,1'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsMessageById($id))
        {
            $message = ChatMessage::where('id', $id)->whereNull('deleted_at')->first();
            if ($request->input('content')) $message->content = $request->input('content');
            if ($request->feedback) $message->feedback = $request->feedback;


            return ($message->save()) ?
                $this->successResponse(new ChatMessageResource($message), 200, 'message-successfully-updated') :
                $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('message-notFound', 404);
    }

    public function destroy($message_id)
    {
        if ($this->checkExistsMessageById($message_id))
        {
            if (Auth::user()->hasAnyRole(['admin', 'developer']))
            {
                if (ChatMessage::where('id',$message_id)->delete())
                    return $this->successResponse('', 200, 'delete-successful');
                return $this->errorResponse('delete-failed', 500);
            }
            else
            {
                if ($this->authorizeMessageByUserId($message_id)['status'] == 'success')
                {
                    if (ChatMessage::where(['id' => $message_id])->delete())
                        return $this->successResponse('', 200, 'delete-successful');
                    return $this->errorResponse('delete-failed', 500);
                }
                return $this->errorResponse('user-notAuthorized', 403);
            }
        }
        return $this->errorResponse('message-notFound', 404);
    }

    public function setFeedbackOnMessage($message_id, $feedback)
    {
        if ($this->checkExistsMessageById($message_id))
        {
            if ($this->authorizeMessageByUserId($message_id)['status'] == 'success')
            {
                $message = ChatMessage::where('id',$message_id)->first();
                if ($message->role == 'ai')
                {
                    if ($feedback == '0' || $feedback == '1')
                    {
                        $message->feedback = $feedback;
                        if ($message->save())
                            return $this->successResponse('', 200, 'update-successful');
                        return $this->errorResponse('update-failed', 500);
                    }
                    return $this->errorResponse('feedback-notExists', 404);
                }
                return $this->errorResponse('human-role', 403);
            }
            return $this->errorResponse('user-notAuthorized', 403);
        }
        return $this->errorResponse('message-notFound', 404);
    }

    public function search(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "content" => 'nullable',

            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            $query = ChatMessage::with('chat_session', 'files')->select("*");
            if ($request->input('content') != null) $query->where("content", "like", "%".$request->input('content')."%");

            return $query->exists() ?
                $this->successResponse(ChatMessageResource::collection($query->get()), 200, 'message-found') :
                $this->errorResponse('message-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }

    private function checkExistsMessageById($id)
    {
        return DB::table('chat_messages')->where('id', $id)->exists();
    }

    private function authorizeMessageByUserId($message_id)
    {
        if ($this->checkExistsMessageById($message_id))
        {
            $session_id = DB::table('chat_messages')->where('id', $message_id)->value('session_id');
            $session = DB::table('chat_sessions')->where('id', $session_id)->first();

            if (!$session)
                return ['status' => 'error', 'message' => 'session-notFound'];

            if ($session->user_id == Auth::id())
                return ['status' => 'success', 'message' => 'authorized'];
            return ['status' => 'error', 'message' => 'user-notAuthorized'];
        }
        return ['status' => 'error', 'message' => 'message-notFound'];
    }
}
