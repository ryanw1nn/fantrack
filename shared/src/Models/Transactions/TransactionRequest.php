<?php

namespace SynergyERP\Shared\Models\Transactions;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use SynergyERP\Shared\Services\JwtHelper;
use SynergyERP\Shared\Services\UlidFactory;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use DateTime;

/**
 * TransactionRequest class
 * 
 * Represents a transaction request in the system
 * Maps to the transaction_events table structure as shown in the class diagram
 */
class TransactionRequest
{
    // Request data
    protected Request $request;
    protected ?string $idempotencyKey = null;
    protected OperationKeyContext $operationKeyContext;
    protected ?array $requestHeaders = null;
    protected array $requestContent;
    protected ?array $requestToken = null;
    
    // Transaction metadata
    protected string $kind; // 'command' or 'query'
    protected string $operation_key; // CQRS key
    protected string $transaction_key; // ULID for transaction
    protected int $model_version = 1;
    protected string $status = 'started';
    protected string $request_hash;
    protected ?string $response_hash = null;
    
    // Principal information — property names line up with the JWT claims
    // (`principal_puid`, `delegated_puid`) and the DB columns
    // (`principal_puid`).
    protected ?string $principalPuid = null;
    protected ?string $delegatedPuid = null;

    // Timestamps
    protected ?DateTime $received_at = null;
    protected ?DateTime $executed_at = null;
    protected ?DateTime $published_at = null;
    protected ?DateTime $centralized_at = null;

    // Token data
    private ?string $schema = null;
    private ?string $email = null;
    private ?string $tenantPuid = null;
    private DateTime $requestedAt;
    private string $requestedBy;

    /**
     * Create a new HTTP client instance
     *
     * @param Request $request The HTTP request object
     * @param OperationKeyContext $operationKeyContext The operation key context
     */
    public function __construct(Request $request, OperationKeyContext $operationKeyContext)
    {
        $this->request = $request;
        $this->operationKeyContext = $operationKeyContext;
        $this->requestedAt = new DateTime('now');
        $this->received_at = new DateTime('now');
        
        // Initialize transaction metadata
        $this->kind = $operationKeyContext->getOperationComponent('cqrs');
        $this->operation_key = $operationKeyContext->getOperationKey();
        $this->transaction_key = UlidFactory::generateWithHash('transaction', $request->all());
        $this->status = 'started';
        
        // Extract data from request and token
        $this->extractRequestData();
        $this->extractTokenData();
        
        // Calculate request hash
        $this->request_hash = md5(json_encode($this->requestContent));
    }
    
    private function extractRequestData(): void
    {
        $encryptedToken = JwtHelper::getEncryptedToken($this->request);
        if ($encryptedToken) {
            try {
                $this->requestToken = JwtHelper::getDecryptedPayload($encryptedToken);
            } catch (\Exception $e) {
                Log::warning('TransactionRequest: Failed to decrypt token payload', [
                    'error' => $e->getMessage(),
                    'operation_key' => $this->operation_key,
                ]);
                $this->requestToken = null;
            }
        } else {
            $this->requestToken = null;
        }
        $this->requestContent = $this->request->all();
        $this->requestHeaders = $this->request->headers->all();

        // Extract idempotency key from headers
        if ($this->request->header('Idempotency-Key')) {
            $this->idempotencyKey = $this->request->header('Idempotency-Key');
        }
    }

    /**
     * Extract data from the JWT token
     *
     * @return void
     */
    private function extractTokenData(): bool
    {
        try {
            if (!$this->requestToken || !is_array($this->requestToken)) {
                // No valid JWT — fall back to explicit headers (used by inbox worker)
                $this->schema = $this->request->header('X-Tenant-Schema');
                $this->principalPuid = $this->request->header('X-Principal-Puid');
                $this->delegatedPuid = null;
                $this->tenantPuid = null;
                $this->email = null;
                $this->requestedBy = $this->principalPuid ?? 'system';
                return false;
            }

            // Extract payload data
            $this->email = $this->requestToken['email'] ?? null;

            // Try to get schema from token, with fallback to service name from operation key
            $this->schema = $this->requestToken['schema'] ?? null;
            if (!$this->schema && $this->operation_key) {
                $this->schema = explode('.', $this->operation_key)[0] ?? null;
            }

            // Canonical JWT claims carry public_id values: `tenant_puid`,
            // `principal_puid`, `delegated_puid`.
            $this->tenantPuid    = $this->requestToken['tenant_puid']    ?? null;
            $this->principalPuid = $this->requestToken['principal_puid'] ?? null;
            $this->delegatedPuid = $this->requestToken['delegated_puid'] ?? null;
            $this->requestedBy = $this->email ?? 'system';
            return true;
        } catch (\Exception $e) {
            Log::error('TransactionRequest: Failed to extract token data', [
                'error' => $e->getMessage(),
                'operation_key' => $this->operation_key
            ]);

            // Set default values
            $this->schema = null;
            $this->principalPuid = null;
            $this->delegatedPuid = null;
            $this->tenantPuid = null;
            $this->email = null;
            $this->requestedBy = 'system';
        }
        return false;
    }

    /**
     * Get the request content
     * 
     * @return array
     */
    public function getRequestContent(): array
    {
        return $this->requestContent;
    }

    /**
     * Get the operation key context
     * 
     * @return OperationKeyContext
     */
    public function getOperationKeyContext(): OperationKeyContext
    {
        return $this->operationKeyContext;
    }

    /**
     * Get the operation key
     * 
     * @return string
     */
    public function getOperationKey(): string 
    {
        return $this->operation_key;
    }
    
    /**
     * Get the transaction key (ULID)
     * 
     * @return string
     */
    public function getTransactionKey(): string 
    {
        return $this->transaction_key;
    }

    /**
     * Get the request token
     * 
     * @return array|null
     */
    public function getToken(): ?array
    {
        return $this->requestToken;
    }

    /**
     * Get the schema (subdomain) from token
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * Get the principal public_id extracted from the JWT (or headers).
     */
    public function getPrincipalPuid(): ?string
    {
        return $this->principalPuid;
    }

    /**
     * Get the delegated principal public_id (sudo / impersonation).
     */
    public function getDelegatedPuid(): ?string
    {
        return $this->delegatedPuid;
    }

    /**
     * Get the tenant public_id extracted from the JWT.
     */
    public function getTenantPuid(): ?string
    {
        return $this->tenantPuid;
    }


    /**
     * Get the requested at timestamp
     * 
     * @return \DateTime
     */
    public function getRequestedAt(): \DateTime 
    {
        return $this->requestedAt;
    }
    
    /**
     * Get the requested by user
     * 
     * @return string
     */
    public function getRequestedBy(): string 
    {
        return $this->requestedBy;
    }
    
    /**
     * Get the CQRS type (command or query)
     * 
     * @return string
     */
    public function getCqrs(): string 
    {
        return $this->kind;
    }
    
    /**
     * Get the idempotency key
     * 
     * @return string|null
     */
    public function getIdempotencyKey(): ?string 
    {
        return $this->idempotencyKey;
    }
    
    /**
     * Set the idempotency key
     * 
     * @param string $idempotencyKey
     * @return self
     */
    public function setIdempotencyKey(string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;
        return $this;
    }
    
    /**
     * Get the model version
     * 
     * @return int
     */
    public function getModelVersion(): int
    {
        return $this->model_version;
    }
    
    /**
     * Get the status
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    
    /**
     * Get the request hash
     * 
     * @return string
     */
    public function getRequestHash(): string
    {
        return $this->request_hash;
    }
    
    /**
     * Get the response hash
     * 
     * @return string|null
     */
    public function getResponseHash(): ?string
    {
        return $this->response_hash;
    }
    
    /**
     * Get the received at timestamp
     * 
     * @return \DateTime|null
     */
    public function getReceivedAt(): ?\DateTime
    {
        return $this->received_at;
    }
    
    /**
     * Get the executed at timestamp
     * 
     * @return \DateTime|null
     */
    public function getExecutedAt(): ?\DateTime
    {
        return $this->executed_at;
    }
    
    /**
     * Get the published at timestamp
     * 
     * @return \DateTime|null
     */
    public function getPublishedAt(): ?\DateTime
    {
        return $this->published_at;
    }
    
    /**
     * Get the centralized at timestamp
     * 
     * @return \DateTime|null
     */
    public function getCentralizedAt(): ?\DateTime
    {
        return $this->centralized_at;
    }
    
    /**
     * Set the executed at timestamp
     * 
     * @param \DateTime|null $executed_at
     * @return self
     */
    public function setExecutedAt(?\DateTime $executed_at = null): self
    {
        $this->executed_at = $executed_at ?? new DateTime('now');
        $this->status = 'completed';
        return $this;
    }
    
    /**
     * Set the published at timestamp
     * 
     * @param \DateTime|null $published_at
     * @return self
     */
    public function setPublishedAt(?\DateTime $published_at = null): self
    {
        $this->published_at = $published_at ?? new DateTime('now');
        return $this;
    }
    
    /**
     * Set the centralized at timestamp
     * 
     * @param \DateTime|null $centralized_at
     * @return self
     */
    public function setCentralizedAt(?\DateTime $centralized_at = null): self
    {
        $this->centralized_at = $centralized_at ?? new DateTime('now');
        return $this;
    }
    
    /**
     * Set the response hash
     * 
     * @param string $response_hash
     * @return self
     */
    public function setResponseHash(string $response_hash): self
    {
        $this->response_hash = $response_hash;
        return $this;
    }
    
    /**
     * Set the status
     * 
     * @param string $status
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }
    
    /**
     * Set the model version
     * 
     * @param int $model_version
     * @return self
     */
    public function setModelVersion(int $model_version): self
    {
        $this->model_version = $model_version;
        return $this;
    }
}