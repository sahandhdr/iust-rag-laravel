<?php

namespace App\Models\Department;


use App\Models\Document\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory,SoftDeletes;
    protected $table = "departments";
    protected $guarded = [];

    public function parent()
    {
        return $this->belongsTo(Department::class, 'dept_id');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'dept_id')->with('children');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dept_user', 'dept_id', 'user_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'doc_department', 'dept_id', 'doc_id');
    }

}

