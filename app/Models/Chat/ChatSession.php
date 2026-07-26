<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "chat_sessions";
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function chat_messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id')->orderBy('created_at');
    }
}
