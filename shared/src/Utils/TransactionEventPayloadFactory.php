<?php

namespace SynergyERP\Shared\Utils;

use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Models\Events\TransactionEvent;

/**
 * 
 * 
 * @package SynergyCore\Utils
 * @author Alexander Torres 
 */
final class TransactionEventPayloadFactory
{
    public static function make(TransactionEvent $transactionEvent): array {
        $payload = [];
        
        $payload = [
            'kind' => $transactionEvent->getKind(),
            'transaction_key' => $transactionEvent->getTransactionKey(),
            'idempotency_key' => $transactionEvent->getIdempotencyKey() ?? null,
            // 'operation' => [
            //     'service' => $transactionEvent->getOperationService(),
            //     // 'cqrs' => $transactionEvent->getOperationCqrs(),
            //     // 'model' => $transactionEvent->getOperationModel(),
            //     // 'action' => $transactionEvent->getOperationAction(),
            //     //'id' => $transactionEvent->getOperationModelId(),
            // ],
            'model_version' => $transactionEvent->getModelVersion(),
            // 'status' => $transactionEvent->getStatus(),
            // 'request_hash' => $transactionEvent->getRequestHash(),
            // 'response_hash' => $transactionEvent->getResponseHash(),
            // 'principal_id' => $transactionEvent->getPrincipalId(),
            // 'delegated_by' => $transactionEvent->getDelegatedBy(),
            // 'received_at' => self::formatDateTime($transactionEvent->getReceivedAt()),
            // 'executed_at' => self::formatDateTime($transactionEvent->getExecutedAt()),
            // 'published_at' => self::formatDateTime($transactionEvent->getPublishedAt()),
            // 'centralized_at' => self::formatDateTime($transactionEvent->getCentralizedAt()),
        ];

        return $payload;
    }

    private static function formatDateTime(?\DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }
}