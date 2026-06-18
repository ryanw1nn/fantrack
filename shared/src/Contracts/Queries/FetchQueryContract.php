<?php

namespace SynergyERP\Shared\Contracts\Queries;

use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;

/**
 * 
 * @package SynergyERP\Shared\Contracts\Queries
 * @author Alexander Torres
 */
class FetchQueryContract extends Contract                  
{
    private string $table;

    public function __construct(array $requestData, string $tenantSchema, string $table, ?OperationKeyContext $operationKeyContext = null) {
        $this->table = mb_strtolower($table);
        parent::__construct($requestData, $tenantSchema, $operationKeyContext);
    }

    protected function rules(): array
    {
        return [
            'id'         => ['required_without_all:public_id,public_ref', 'nullable', 'integer', 'min:1'],
            'public_id'  => ['required_without_all:id,public_ref',        'nullable', 'string',  'size:26'],
            'public_ref' => ['required_without_all:id,public_id',         'nullable', 'string',  'max:26'],
        ];
    }
}