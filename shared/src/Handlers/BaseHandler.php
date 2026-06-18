<?php

namespace SynergyERP\Shared\Handlers;

use Illuminate\Database\Eloquent\Model;
use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Models\Contracts\SchemaAware;
use Illuminate\Validation\ValidationException;

/**
 * This class contains common logic for command and query handlers
 *
 * @property int $tenantUserId
 * @property Contract $contract
 * @property Model $model
 *
 * @package SynergyERP\Shared\Handlers
 */
abstract class BaseHandler
{
    private Contract $contract;
    private string $modelClass;
    // Principal public_id (ULID char(26)) extracted from the JWT's
    // `principal_puid` claim.
    private string $principalPuid;
    private ?Model $model = null;

    abstract public function handle(): array;

    public function __construct(Contract $contract, string $modelClass, string $principalPuid)
    {
        $this->contract = $contract;
        $this->principalPuid = $principalPuid;
        $this->modelClass = $modelClass;
    }

    /**
     * Get the principal public_id.
     */
    public function getPrincipalPuid(): string
    {
        return $this->principalPuid;
    }

    /**
     * Get a new model instance. For SchemaAware models, the per-request
     * tenant schema is primed from the contract; for manifest-scoped
     * models (SystemModel, AuthModel) no priming is needed and the
     * $shouldApplyTenantSchema hint is ignored.
     */
    public function getNewModel(bool $shouldApplyTenantSchema = true): Model
    {
        $modelClass = $this->modelClass;
        $model = new $modelClass();
        if ($shouldApplyTenantSchema && $model instanceof SchemaAware) {
            $this->applyTenantSchema($model);
        }
        return $model;
    }
    public function getModelById(int $id): Model
    {
        if ($this->model === null) {
            $this->model = $this->getNewModel();
        }
        return $this->model->find($id);
    }

    public function getModelByPublicId(string $publicId): Model
    {
        if ($this->model === null) {
            $this->model = $this->getNewModel();
        }
        return $this->model->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Apply the per-request tenant schema to a SchemaAware model.
     */
    protected function applyTenantSchema(SchemaAware $model): void
    {
        $model::setTransactionSchema($this->contract->getTenantSchema());
    }

    /**
     * Get the validated data from the contract
     * @return array The validated data
     */
    protected function getValidatedData(): array
    {
        return $this->contract->getValidatedData();
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }
}
