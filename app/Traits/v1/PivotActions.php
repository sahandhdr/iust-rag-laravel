<?php

namespace App\Traits\v1;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait PivotActions
{
    use ApiResponser;
    /**
     * Attach Value_2 to Value_1
     */
    protected function attach($table, $relatedTable, $pivotTable, $foreignPivotKey, $relatedPivotKey, $value1, $value2)
    {
        if (!DB::table($table)->where('id', $value1)->exists()) {
            return $this->errorResponse(Str::singular($table) . '-notFound', 404);
        }

        if (!DB::table($relatedTable)->where('id', $value2)->exists()) {
            return $this->errorResponse(Str::singular($relatedTable) . '-notFound', 404);
        }

        if (DB::table($pivotTable)->where([$foreignPivotKey => $value1, $relatedPivotKey => $value2])->exists()) {
            return $this->errorResponse(Str::singular($table) . '-has-' . Str::singular($relatedTable), 400);
        }

        if (DB::table($pivotTable)->insert([$foreignPivotKey => $value1, $relatedPivotKey => $value2])) {
            return $this->successResponse('', 200, 'successfully-attached');
        }

        return $this->errorResponse('attach-failed', 500);
    }

    /**
     * Detach Value_2 from Value_1
     */
    protected function detach($table, $relatedTable, $pivotTable, $foreignPivotKey, $relatedPivotKey, $value1, $value2)
    {
        if (!DB::table($table)->where('id', $value1)->exists()) {
            return $this->errorResponse(Str::singular($table) . '-notFound', 404);
        }

        if (!DB::table($relatedTable)->where('id', $value2)->exists()) {
            return $this->errorResponse(Str::singular($relatedTable) . '-notFound', 404);
        }

        if (!DB::table($pivotTable)->where([$foreignPivotKey => $value1, $relatedPivotKey => $value2])->exists()) {
            return $this->errorResponse(Str::singular($table) . '-' . Str::singular($relatedTable) . '-notFound', 404   );
        }

        if (DB::table($pivotTable)->where([$foreignPivotKey => $value1, $relatedPivotKey => $value2])->delete() > 0) {
            return $this->successResponse(null, 200, 'successfully-detached');
        }

        return $this->errorResponse('detach-failed', 500);
    }

    /**
     * Sync Value_2 to Value_1
     */
    protected function sync($table, $relatedTable, $pivotTable, $foreignPivotKey, $relatedPivotKey, $value1, $values2)
    {
        // check value_1 exists
        if (DB::table($table)->where('id', $value1)->exists()) {
            DB::beginTransaction();

            // check exists value_1 in pivot
            if (DB::table($pivotTable)->where($foreignPivotKey, $value1)->exists()) {
                // delete all value_1 from pivot
                if (DB::table($pivotTable)->where($foreignPivotKey, $value1)->delete() <= 0) {
                    DB::rollBack();
                    return $this->errorResponse('delete-all-' . Str::singular($table) . '-failed', 500);
                }
            }

            // set counter
            $counter = 0;

            foreach ($values2 as $value2) {
                // check value_2 exists
                if (DB::table($relatedTable)->where('id', $value2)->exists()) {
                    // insert in pivot
                    if (DB::table($pivotTable)->insert([$foreignPivotKey => $value1, $relatedPivotKey => $value2])) {
                        $counter++;
                    } else {
                        DB::rollBack();
                        return $this->errorResponse('insert-' . Str::singular($relatedTable) . '-failed', 500);
                    }
                } else {
                    DB::rollBack();
                    return $this->errorResponse(Str::singular($relatedTable) . '-notFound', 404);
                }
            }

            // check counter is equal with count of values_2
            if ($counter == count($values2)) {
                DB::commit();
                return $this->successResponse(null, 200, 'successfully-sync');
            }

            DB::rollBack();
            return $this->errorResponse('sync-failed', 500);
        }
        return $this->errorResponse(Str::singular($table) . '-notFound', 404);
    }
}
