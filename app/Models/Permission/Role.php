<?php

namespace App\Models\Permission;

use App\Models\Document\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "roles";
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'role_position', 'role_id', 'position_id')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')->withTimestamps();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'doc_role', 'role_id', 'doc_id')->withTimestamps();
    }

    /* ------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    public function hasPermission($permission): bool
    {
        return $this->permissions()->where('title_en', $permission)->exists();
    }
}
