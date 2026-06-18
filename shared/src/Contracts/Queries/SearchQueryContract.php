<?php

namespace SynergyERP\Shared\Contracts\Queries;

use Illuminate\Validation\Rule;
use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;

class SearchQueryContract extends Contract
{
    private string $table;
    private array $searchableColumns;

    public function __construct(
        array $requestData,
        string $tenantSchema,
        string $table,
        array $searchableColumns,
        ?OperationKeyContext $operationKeyContext = null
    ) {
        $this->table = mb_strtolower($table);
        $this->searchableColumns = $searchableColumns;
        parent::__construct($requestData, $tenantSchema, $operationKeyContext);
    }

    protected function afterValidation(): void
    {
        // check if the colunms types match the types for the operation 

        $typeOperatorMap = [
            'string' => ['=', '!=', 'LIKE'],
            'text' => ['=', '!=', 'LIKE'],
            'integer' => ['=', '!=', '<', '<=', '>', '>='],
            'bigint' => ['=', '!=', '<', '<=', '>', '>='],
            'decimal' => ['=', '!=', '<', '<=', '>', '>='],
            'float' => ['=', '!=', '<', '<=', '>', '>='],
            'boolean' => ['=', '!='],
            'datetime' => ['=', '!=', '<', '<=', '>', '>='],
            'date' => ['=', '!=', '<', '<=', '>', '>='],
        ];

        $columns = [
            'nickname' => 'string',
            'email' => 'string',
            'phone' => 'string',
            'status' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];

        // foreach ($this->getValidatedData()['where'] ?? [] as $index => $filter) {
        //     $column = $filter['column'] ?? null;
        //     $operator = $filter['operator'] ?? null;

        //     if (!$column || !$operator || !isset($columns[$column])) {
        //         continue;
        //     }



        //     $type = $columns[$column]; // e.g., 'decimal'
        //     $allowedOperators = $typeOperatorMap[$type] ?? [];

        //     if (!in_array($operator, $allowedOperators)) {
        //         throw ValidationException::withMessages([
        //             "where.$index.operator" => "Operator '$operator' is not valid for column type '$type'. Allowed: " . implode(', ', $allowedOperators),
        //         ]);
        //     }
        // }
    }

    protected function rules(): array
    {
        return [
            'where' => ['nullable', 'array'],
            'where.*.column' => [
                'required_with:where',
                'string',
                Rule::in($this->searchableColumns),
            ],
            'where.*.operator' => [
                'required_with:where',
                'string',
                Rule::in(['=', '!=', '<', '>', '<=', '>=', 'LIKE']),
            ],
            'where.*.value' => ['required_with:where'],

            'sort' => ['nullable', 'array'],
            'sort.*.column' => [
                'required_with:sort',
                'string',
                Rule::in($this->searchableColumns),
            ],
            'sort.*.direction' => ['required_with:sort', 'in:asc,desc'],

            'group_by' => ['nullable', 'string', Rule::in($this->searchableColumns)],
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
