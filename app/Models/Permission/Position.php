<?php

namespace App\Models\Permission;

use App\Models\Department\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use HasFactory,SoftDeletes;
    protected $table = "positions";
    protected $guarded = [];

    public function department()
    {
        return $this->belongsTo(Department::class, 'dept_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_position', 'position_id', 'permission_id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_position', 'position_id', 'role_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'position_user', 'position_id', 'user_id');
    }

    public function parent()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function children()
    {
        return $this->hasMany(Position::class, 'position_id')->with('children');
    }
}
