<?php

namespace SynergyERP\Shared\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * QueryBuilderMiddleware
 *
 * Middleware to transform a validated JSON body into a safe SQL query.
 *
 * Usage: Inject into controllers or as a service, passing table name and JSON body.
 * Security: Only allows whitelisted fields/operators.
 */
class QueryBuilder
{
    // Define allowed fields and operators for security
    protected array $allowedFields;
    protected array $allowedOperators = [
        '=', '!=', '>', '<', '>=', '<=', 'LIKE', 'IS', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'
    ];

    public function __construct(array $allowedFields)
    {
        $this->allowedFields = $allowedFields;
    }

    /**
 * Apply query filters, select, order, and limit to a Builder instance.
 *
 * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
 * @param array $queryBody
 * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
 */
public static function apply($query, array $queryBody)
    {
        // Filters
        if (!empty($queryBody['filters'])) {
            foreach ($queryBody['filters'] as $filter) {
                if (isset($filter['field'], $filter['operator'])) {
                    $operator = strtoupper($filter['operator']);
                    if ($operator === 'IS' && $filter['value'] === null) {
                        $query->whereNull($filter['field']);
                    } else {
                        $query->where($filter['field'], $filter['operator'], $filter['value']);
                    }
                }
            }
        }
        // Select
        if (!empty($queryBody['select'])) {
            $query->select($queryBody['select']);
        }
        // Order By
        if (!empty($queryBody['order_by'])) {
            foreach ($queryBody['order_by'] as $order) {
                $parts = preg_split('/\s+/', trim($order));
                $field = $parts[0];
                $direction = isset($parts[1]) ? $parts[1] : 'ASC';
                $query->orderBy($field, $direction);
            }
        }
        // Limit
        if (!empty($queryBody['limit'])) {
            $query->limit($queryBody['limit']);
        }
        return $query;
    }

    /**
     * Build a query from JSON body.
     * @param string $table
     * @param array $body
     * @return \Illuminate\Database\Query\Builder
     * @throws InvalidArgumentException
     */
    public function buildQuery(string $table, array $body)
    {
        $query = DB::table($table);

        // Handle select
        if (!empty($body['select']) && is_array($body['select'])) {
            foreach ($body['select'] as $field) {
                $this->validateField($field);
            }
            $query->select($body['select']);
        }

        // Handle filters
        if (!empty($body['filters']) && is_array($body['filters'])) {
            foreach ($body['filters'] as $filter) {
                $this->validateField($filter['field']);
                $this->validateOperator($filter['operator']);
                if (strtoupper($filter['operator']) === 'IS' && $filter['value'] === null) {
                    $query->whereNull($filter['field']);
                } else {
                    $query->where($filter['field'], $filter['operator'], $filter['value']);
                }
            }
        }

        // Handle order_by
        if (!empty($body['order_by']) && is_array($body['order_by'])) {
            foreach ($body['order_by'] as $order) {
                // e.g., "start_at DESC"
                if (preg_match('/^([a-zA-Z0-9_]+)\s+(ASC|DESC)$/i', $order, $matches)) {
                    $this->validateField($matches[1]);
                    $query->orderBy($matches[1], strtoupper($matches[2]));
                } else {
                    throw new InvalidArgumentException("Invalid order_by format: $order");
                }
            }
        }

        // Handle limit
        if (!empty($body['limit']) && is_numeric($body['limit'])) {
            $query->limit((int)$body['limit']);
        }

        return $query;
    }

    protected function validateField($field)
    {
        if (!in_array($field, $this->allowedFields, true)) {
            throw new InvalidArgumentException("Field '$field' is not allowed.");
        }
    }

    protected function validateOperator($operator)
    {
        if (!in_array(strtoupper($operator), $this->allowedOperators, true)) {
            throw new InvalidArgumentException("Operator '$operator' is not allowed.");
        }
    }
}
