<?php

namespace SynergyERP\Shared\Handlers\Queries;

use Illuminate\Database\Eloquent\Model;
use SynergyERP\Shared\Handlers\BaseHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SearchQueryHandler extends BaseHandler
{
    public function handle(): array
    {
        $model = $this->getNewModel();
        $query = $this->getValidatedData();
        $results = $this->buildQueryFromJson($query, $model);
        return $results;
    }

    private function buildQueryFromJson(array $filters, Model $model): array
    {
        $tableName = $model->getTable();
        \Illuminate\Support\Facades\Log::info('SearchQueryHandler: Building query', [
            'table' => $tableName,
            'filters' => $filters
        ]);
        
        $query = DB::table($tableName);

        // Silent default: skip soft-deleted rows. The frontend collection
        // never asks for them, so this is an invariant — it's intentionally
        // NOT surfaced as a user-facing "active filter." Gated on the column
        // existing so models without SoftDeletes aren't affected.
        // Extract just the table name (without schema prefix) for Schema::hasColumn check
        $tableOnly = strpos($tableName, '.') !== false 
            ? explode('.', $tableName)[1] 
            : $tableName;
        if (Schema::hasColumn($tableOnly, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        // Handle WHERE conditions
        if (!empty($filters['where'])) {
            foreach ($filters['where'] as $condition) {
                $query->where(
                    $condition['column'],
                    $condition['operator'],
                    $condition['value']
                );
            }
        }

        // Handle SORTING
        if (!empty($filters['sort'])) {
            foreach ($filters['sort'] as $sort) {
                $query->orderBy($sort['column'], $sort['direction'] ?? 'asc');
            }
        }

        // Handle GROUP BY
        if (!empty($filters['group_by'])) {
            $query->groupBy($filters['group_by']);
        }

        // Handle LIMIT
        if (!empty($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        // Log the SQL query before execution
        \Illuminate\Support\Facades\Log::info('SearchQueryHandler: Executing query', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings()
        ]);

        $results = $query->get()->toArray();
        
        \Illuminate\Support\Facades\Log::info('SearchQueryHandler: Query results', [
            'count' => count($results)
        ]);

        return $results;
    }
}