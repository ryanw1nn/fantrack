<?php

namespace SynergyERP\Shared\Outbox;

use RuntimeException;
use SynergyERP\Shared\Models\Events\TransactionEvent;


final class OutboxDestinationResolver
{
    /**
     * @return array{exchange:string,routing_key:string}
     */
    public function resolve(TransactionEvent $transactionEvent): array
    {
        $service = $transactionEvent->operation_service ?? null;
        $cqrs = $transactionEvent->operation_cqrs ?? null;
        $model = $transactionEvent->operation_model ?? null;
        $action = $transactionEvent->operation_action ?? null;

        if (! $service || ! $cqrs || ! $model || ! $action) {
            throw new RuntimeException(
                'Cannot create outbox item because operation_* components are missing on TransactionEvent'
            );
        }

        return [
            'exchange' => sprintf('%s.exchange', $cqrs),
            'routing_key' => sprintf('%s.%s.%s.%s', $service, $cqrs, $model, $action),
        ];
    }
}