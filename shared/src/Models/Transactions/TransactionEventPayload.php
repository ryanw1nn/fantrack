<?php

namespace SynergyERP\Shared\Models\Transactions;

/**
 * Immutable value object that carries the domain-level data needed to
 * populate an OutboxItem payload. This decouples the outbox write from
 * the Eloquent TransactionEvent model - the model stays a persistence
 * concern, this class stays a messaging concern.
 */

class TransactionEventPayload {
    public function __construct(
        private readonly TransactionKeyContext  $transactionKeyContext,
        private readonly TransactionRequest     $request,
        private readonly ?TransactionResponse   $response = null,
        private readonly ?array                 $snapshot = null,
        private readonly ?array                 $projection = null,
    ) {}

    // Accessors
    public function getTransactionKeyContext(): TransactionKeyContext
    {
        return $this->transactionKeyContext;
    }

    public function getIdempotencyKey(): string
    {
        return $this->request->getIdempotencyKey();
    }

    public function getRequest(): TransactionRequest
    {
        return $this->request;
    }

    public function getResponse(): ?TransactionResponse
    {
        return $this->response;
    }

    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }

    public function getProjection(): ?array
    {
        return $this->projection;
    }

    public function getRequestedBy(): string
    {
        return $this->request->getRequestedBy();
    }

    public function getRequestedAt(): \DateTime
    {
        return $this->request->getRequestedAt();
    }
}
