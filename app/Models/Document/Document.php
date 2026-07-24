<?php

namespace App\Models\Document;

use App\Models\Department\Department;
use App\Models\Permission\Permission;
use App\Models\Permission\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory;
    protected $table = "documents";
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'doc_role', 'doc_id', 'role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'doc_permission', 'doc_id', 'permission_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'doc_department', 'doc_id', 'dept_id');
    }
}
