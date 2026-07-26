<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "chat_messages";
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'sources'    => 'array',
            'feedback'   => 'boolean', // '1' / '0' → true/false
            'deleted_at' => 'datetime',
        ];
    }

    public function chat_session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ChatMessageFile::class, 'message_id');
    }
}
