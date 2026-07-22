<?php

namespace App\Repositories;

use App\Repositories\Interfaces\BaseRepositoryInterfaces;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BaseRepository implements BaseRepositoryInterfaces
{

    public Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function index(array $relations = []): Collection
    {
        return $this->model->with($relations)->get();
    }

    public function store(array $attributes): ?Model
    {
        return $this->model->query()->create($attributes);
    }

    public function update(array $attributes, int $id): bool
    {
        return $this->model->query()->where('id', $id)->update($attributes);
    }

    public function show(int $id, array $relations = []): ?Model
    {
        return $this->model->query()->with($relations)->where('id', $id)->first();
    }

    public function delete(int $id): bool
    {
        return $this->model->query()->where('id', $id)->delete();
    }

    public function search(array $columns = ['*'], array $relations = [], array $filters = []): Collection
    {
        $query = $this->model->query()
            ->select($columns)
            ->with($relations);

        $table = $this->model->getTable();
        $allColumns = Schema::getColumnListing($table);

        foreach ($filters as $key => $value) {
            if ($key === 'search' && is_string($value)) {
                $query->where(function ($q) use ($value, $allColumns) {
                    foreach ($allColumns as $field) {
                        $q->orWhere($field, 'like', "%$value%");
                    }
                });
            } elseif (in_array($key, $allColumns)) {
                $query->where($key, $value);
            }
        }

        return $query->get();
    }

    public function checkExists(int $id): bool
    {
        return $this->model->query()->where('id', $id)->exists();
    }
}
