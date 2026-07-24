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

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'role_position', 'role_id', 'position_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'role_id', 'permission_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'doc_role', 'role_id', 'doc_id');
    }
    public function hasPermission($permission)
    {
        return $this->permissions()->where('name_en', $permission)->exists();
    }
}
