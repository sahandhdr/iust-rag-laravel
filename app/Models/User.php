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
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'dept_user', 'user_id', 'dept_id');
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

    public function hasRole($role)
    {
        return $this->roles()->where('name_en', $role)->exists();
    }
}
