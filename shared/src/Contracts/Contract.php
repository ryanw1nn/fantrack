<?php

namespace SynergyERP\Shared\Contracts;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;

/**
 * This class contains shared logic between command and query contracts. This class contains logic for 
 * validating the requestData. This class is responsible for throwing a ValidationException if 
 * the validation fails. 
 * 
 * @author Alexander Torres
 * @package SynergyERP\Shared\Contracts
 */
abstract class Contract
{
    // store the validatedData from the requestData
    protected array $validatedData;
    private string $tenantSchema;
    // protected: subclasses need to inject server-generated fields
    // (public_id / public_ref / version / ulid) before validate() runs, so
    // the validator sees them and hardcoded `required` rules in concrete
    // create contracts pass.
    protected array $requestData;
    private ?OperationKeyContext $operationKeyContext = null;
    // Authenticated principal's public_id, extracted from the JWT by the
    // calling controller. Nullable because query contracts may be invoked
    // in contexts where the caller is anonymous (public reads), and older
    // callers that haven't been updated still work — the value defaults to
    // null and contracts that need it can guard accordingly.
    private ?string $principalPuid = null;

    /**
     * Returns the validation rules that will be used to validate the request data.
     *
     * @return array
     */
    abstract protected function rules(): array;

    /**
     * Constructor for the BaseContract
     *
     * @param array $requestData
     * @param string $tenantSchema
     * @param OperationKeyContext|null $operationKeyContext
     * @param string|null $principalPuid  Authenticated principal's public_id
     *                                    (from the JWT). Optional so legacy
     *                                    test harnesses still instantiate
     *                                    contracts with three args.
     */
    public function __construct(
        array $requestData,
        string $tenantSchema,
        ?OperationKeyContext $operationKeyContext = null,
        ?string $principalPuid = null
    ) {
        $this->tenantSchema = $tenantSchema;
        $this->requestData = $requestData;
        $this->operationKeyContext = $operationKeyContext;
        $this->principalPuid = $principalPuid;

        Log::info('Contract: Constructor called', [
            'contract_class' => get_class($this),
            'tenant_schema' => $tenantSchema,
            'request_data' => $requestData,
            'request_data_keys' => array_keys($requestData)
        ]);

        // Lifecycle: let subclasses augment $requestData (e.g. inject
        // server-generated public_id / public_ref / version) BEFORE the
        // validator runs. Without this hook, any concrete contract that
        // declares those columns as `required` would reject the payload.
        $this->beforeValidation();

        $this->validate();
    }

    /**
     * This method is called BEFORE validation runs. Override this in child
     * classes to inject server-generated fields (ULIDs, row versions, etc.)
     * into $this->requestData so the validator sees them and `required`
     * rules pass.
     *
     * @return void
     */
    protected function beforeValidation(): void
    {
        // Default implementation does nothing
    }
    
    /**
     * This method is called after validation is complete. Override this method in child classes
     * to add any post-validation logic.
     * 
     * @return void
     */
    protected function afterValidation(): void
    {
        // Default implementation does nothing
    }

    /**
     * Get the qualified table name with tenant schema
     * 
     * @param string $table
     * @return string
     */
    final protected function qualifiedTable(string $table): string
    {
        if (strpos($table, '.') !== false) {
            return $table;
        }

        return "{$this->tenantSchema}.{$table}";
    }

    /**
     * This method would used the array of rules returned by the rules method defined in
     * child classes to check and make sure the requestData is valid. If data is valid it would set
     * the requestData to the $validatedData field. If the data is invalid it would throw a 
     * ValidationException.
     * 
     * @throws ValidationException
     * @return void
     */
    final public function validate(): void 
    {
        // validate the requestData
        $validator = Validator::make(
            $this->requestData,
            $this->rules()
        );

        if ($validator->passes()) {
            // set the validated data to the requestData that was validated
            $this->validatedData = $validator->validated();
            $this->afterValidation();
        } else {
            throw new ValidationException($validator);
        }
    }

    /**
     * Get the validated data from the requestData. Throws an
     * exception if the requestData has not been validated
     * 
     * @throws \LogicException
     * @return array
     */
    final public function getValidatedData(): array
    {
        if ($this->validatedData === null) {
            throw new \LogicException('Contract has not been validated.');
        }

        return $this->validatedData;
    }

    /**
     * Get the tenant schema
     * 
     * @return string
     */
    final public function getTenantSchema(): string
    {
        return $this->tenantSchema;
    }

    /**
     * Get the action name from the operation key context
     * 
     * @return string|null
     */
    protected function getAction(): ?string
    {
        return $this->operationKeyContext?->getOperationComponent('action');
    }

    /**
     * Get the model name from the operation key context
     *
     * @return string|null
     */
    protected function getModelName(): ?string
    {
        return $this->operationKeyContext?->getOperationComponent('model');
    }

    /**
     * Authenticated principal's public_id (ULID) as extracted from the JWT
     * by the entrypoint. Null when the caller is anonymous or when an
     * older caller instantiated the contract without passing it through.
     *
     * @return string|null
     */
    protected function getPrincipalPuid(): ?string
    {
        return $this->principalPuid;
    }
}
