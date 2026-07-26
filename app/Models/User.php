<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Chat\ChatSession;
use App\Models\Department\Department;
use App\Models\Document\Document;
use App\Models\Log\Log;
use App\Models\Permission\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    use SoftDeletes;
    protected $table = "users";
    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deleted_at'       => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')->withTimestamps();;
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user', 'user_id', 'dept_id')->withTimestamps();
    }

    public function chat_sessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'uploader_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(Log::class, 'user_id');
    }

    /* ------------------------------------------------------------------
     | Authorization Helpers
     | ------------------------------------------------------------------ */

    public function hasPermission($permission)
    {
        foreach ($this->roles as $role)
        {
            if ($role->hasPermission($permission))
            {
                return true;
            }
        }
        return false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    public function hasRole($role)
    {
        return $this->roles()->where('title_en', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('title_en', $roles)->exists();
    }

    /**
     * بررسی دسترسی به یک سند بر اساس تگ‌های Role / Department
     * (پایه Phase 1 + Phase 2)
     */
    public function canAccessDocument(Document $document): bool
    {
        // کاربر admin یا developer همیشه دسترسی دارد
        if ($this->hasAnyRole(['admin', 'developer'])) {
            return true;
        }

        // اسناد public برای همه قابل دسترسی هستند
        if ($document->roles()->where('title_en', 'public')->exists()) {
            return true;
        }

        // بررسی تگ Role
        $userRoleIds = $this->roles()->pluck('roles.id');
        if ($document->roles()->whereIn('roles.id', $userRoleIds)->exists()) {
            return true;
        }

        // بررسی تگ Department
        $userDeptIds = $this->departments()->pluck('departments.id');
        if ($document->departments()->whereIn('departments.id', $userDeptIds)->exists()) {
            return true;
        }

        return false;
    }

}
