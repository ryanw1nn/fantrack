<?php

namespace SynergyERP\Shared\Outbox;

use SynergyERP\Shared\Models\Events\TransactionEvent;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;

final class TransactionEventPayloadFactory
{
    public static function make(TransactionEvent $transactionEvent, ?TransactionRequest $transactionRequest = null): array
    {
        return [
            'kind' => $transactionEvent->getKind(),
            'transaction_key' => $transactionEvent->getTransactionKey(),
            'idempotency_key' => $transactionEvent->getIdempotencyKey(),
            'model_version' => $transactionEvent->getModelVersion(),
            'request_hash' => $transactionEvent->getRequestHash(),
            'response_hash' => $transactionEvent->getResponseHash(),
            'status' => $transactionEvent->getStatus(),
            'principal_puid' => $transactionEvent->getPrincipalPuid(),
            'delegated_by' => $transactionEvent->getDelegatedBy(),
            'received_at' => $transactionEvent->getReceivedAt()?->format('Y-m-d H:i:s'),
            'executed_at' => $transactionEvent->getExecutedAt()?->format('Y-m-d H:i:s'),
            'published_at' => $transactionEvent->getPublishedAt()?->format('Y-m-d H:i:s'),
            'centralized_at' => $transactionEvent->getCentralizedAt()?->format('Y-m-d H:i:s'),
            'schema' => $transactionRequest?->getSchema(),
            'operation' => [
                'service' => strtolower($transactionEvent->operation_service ?? null),
                'cqrs' => strtolower($transactionEvent->operation_cqrs ?? null),
                'model' => strtolower($transactionEvent->operation_model ?? null),
                'action' => strtolower($transactionEvent->operation_action ?? null),
                'id' => $transactionEvent->operation_model_id ?? null,
            ],
            'transaction_request' => $transactionRequest?->getRequestContent() ?? [],
        ];
    }
}