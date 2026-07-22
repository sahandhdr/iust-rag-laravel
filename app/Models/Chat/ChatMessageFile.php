<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessageFile extends Model
{
    use HasFactory;

    protected $table = "chat_message_files";
    protected $guarded = [];

    public function chat_message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
