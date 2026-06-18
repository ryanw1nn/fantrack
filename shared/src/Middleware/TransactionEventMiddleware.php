<?php

namespace SynergyERP\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Services\TransactionEventService;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;
use SynergyERP\Shared\Models\Transactions\TransactionResponse;
use SynergyERP\Shared\Models\Transactions\TransactionError;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Models\Operations\OperationResult;


class TransactionEventMiddleware
{
    protected TransactionEventService $transactionEventService;

    public function __construct(TransactionEventService $transactionEventService)
    {

        $this->transactionEventService = $transactionEventService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $operationKey = null)
    {
        if (!$operationKey) {
            return $this->emergencyResponse(
                new \LogicException('Operation key is required for TransactionEventMiddleware'),
                null,
                501
            );
        }

        // Pre-declare so the outer catch can reference it without triggering
        // an "undefined variable" notice if construction itself throws.
        $operationKeyContext = null;
        $transactionRequest  = null;

        try {
            // Construction lives inside the try so any failure here (malformed
            // operation key, malformed JWT, etc.) routes through the same
            // formatter as everything else instead of escaping uncaught.
            $operationKeyContext = new OperationKeyContext($operationKey);
            $transactionRequest  = new TransactionRequest($request, $operationKeyContext);

            Log::info('TransactionEventMiddleware: Processing request', [
                'operation'    => $operationKeyContext->getOperation(),
                'service'      => $operationKeyContext->getOperationComponent('service'),
                'schema'       => $transactionRequest->getSchema(),
                'service_name' => $operationKeyContext->getOperationComponent('service'),
            ]);

            // instantiate transaction event
            $transactionEvent = $this->transactionEventService->createTransactionEvent($transactionRequest);

            // expose event to downstream middleware (CacheMiddleware)
            $request->attributes->set('transaction_event', $transactionEvent);

            // If the transaction event is already completed, return the stored response
            if ($transactionEvent->status === 'completed' || $transactionEvent->status === 'failed') {
                $transactionResponse = new TransactionResponse(
                    $operationKeyContext,
                    $transactionEvent->transaction_response
                );
                return $transactionResponse->toClientResponse();
            }

            // Continue with the request - wrap in try-catch to handle exceptions from controllers
            Log::warning('TransactionEventMiddleware: Before handler execution', [
                'operation' => $operationKeyContext->getOperation(),
            ]);

            try {
                $handlerResponse = $next($request);

                Log::warning('TransactionEventMiddleware: After handler execution', [
                    'operation'      => $operationKeyContext->getOperation(),
                    'response_class' => is_object($handlerResponse) ? get_class($handlerResponse) : gettype($handlerResponse),
                ]);

                // Check if this is a RedirectResponse with validation errors.
                // If so, throw exception immediately - do NOT use OperationResult for errors.
                if ($handlerResponse instanceof \Illuminate\Http\RedirectResponse) {
                    $session = $handlerResponse->getSession();
                    if ($session && $session->has('errors')) {
                        $errors = $session->get('errors');

                        // Create a ValidationException from the session errors
                        $validationException = new \Illuminate\Validation\ValidationException(
                            \Illuminate\Support\Facades\Validator::make([], [])
                        );

                        // Set the errors on the exception
                        $validationException->validator->getMessageBag()->merge($errors->getBag('default')->getMessages());

                        Log::error('TransactionEventMiddleware: Validation error detected in RedirectResponse', [
                            'operation' => $operationKeyContext->getOperation(),
                            'errors'    => $errors->getBag('default')->getMessages(),
                        ]);

                        // Throw so it lands in the inner catch below and gets the
                        // standard error-response treatment instead of being mistaken
                        // for a successful redirect.
                        throw $validationException;
                    }
                }

                // Only use OperationResult for successful responses (no errors)
                $operationResult = new OperationResult($handlerResponse, $operationKeyContext);
                $resultArray     = $operationResult->toArray();

                // read cache source attribution set by CacheMiddleware
                $transactionEvent->response_source = $request->attributes->get('cache_source', 'db');

                Log::warning('TransactionEventMiddleware: OperationResult converted', [
                    'operation'    => $operationKeyContext->getOperation(),
                    'result_array' => $resultArray,
                    'result_count' => count($resultArray),
                ]);

                $transactionResponse = new TransactionResponse(
                    $operationKeyContext,
                    $resultArray
                );

                $this->transactionEventService->addTransactionResponse(
                    $transactionEvent,
                    $transactionResponse
                );

                // TODO: ensure that TransactionEvent is processed async
                Log::warning('TransactionEventMiddleware: Before processTransactionEvent', [
                    'operation' => $operationKeyContext->getOperation(),
                ]);
                $this->transactionEventService->processTransactionEvent($transactionEvent, $transactionRequest);

                Log::warning('TransactionEventMiddleware: After processTransactionEvent', [
                    'operation' => $operationKeyContext->getOperation(),
                ]);

                return $transactionResponse->toClientResponse();

            } catch (\Throwable $handlerErr) {
                // Handler / contract / DB failure path — render via TransactionError +
                // TransactionResponse. Wrap that pipeline in its own try so a secondary
                // failure (e.g. TransactionError chokes on the throwable) still ships a
                // body via the static emergency response instead of a bare 500.
                Log::error('Handler execution error', [
                    'error_type' => get_class($handlerErr),
                    'error'      => $handlerErr->getMessage(),
                    'operation'  => $operationKeyContext?->getOperation(),
                    'trace'      => $handlerErr->getTraceAsString(),
                ]);

                try {
                    $transactionError    = new TransactionError($handlerErr);
                    $transactionResponse = new TransactionResponse(
                        $operationKeyContext,
                        [],
                        $transactionError
                    );
                    $this->transactionEventService->addTransactionResponse(
                        $transactionEvent,
                        $transactionResponse
                    );
                    $this->transactionEventService->processTransactionEvent($transactionEvent, $transactionRequest);
                    return $transactionResponse->toClientResponse();
                } catch (\Throwable $fatalErr) {
                    return $this->emergencyResponse($handlerErr, $fatalErr);
                }
            }

        } catch (\Throwable $middlewareErr) {
            // Failures in middleware setup itself (operation key parse,
            // TransactionRequest construction, createTransactionEvent, ...).
            // Same defense-in-depth pattern: try the standard formatter, fall
            // back to the static body if it explodes.
            Log::error('Transaction event middleware error', [
                'error_type' => get_class($middlewareErr),
                'error'      => $middlewareErr->getMessage(),
                'trace'      => $middlewareErr->getTraceAsString(),
            ]);

            try {
                $transactionError    = new TransactionError($middlewareErr);
                $transactionResponse = new TransactionResponse(
                    $operationKeyContext,
                    [],
                    $transactionError
                );
                return $transactionResponse->toClientResponse();
            } catch (\Throwable $fatalErr) {
                return $this->emergencyResponse($middlewareErr, $fatalErr);
            }
        }
    }

    /**
     * Last-resort response when the standard formatter cannot run.
     *
     * Uses ONLY Laravel's response()->json() helper with a literal array —
     * no instantiation of TransactionError / TransactionResponse / the
     * service, so we can't recurse into the same failure mode that put
     * us here. Logging is best-effort.
     */
    private function emergencyResponse(\Throwable $original, ?\Throwable $secondary, int $status = 500)
    {
        try {
            Log::critical('TransactionEventMiddleware: emergency response', [
                'original_class'    => get_class($original),
                'original_message'  => $original->getMessage(),
                'secondary_class'   => $secondary ? get_class($secondary) : null,
                'secondary_message' => $secondary?->getMessage(),
                'trace'             => $original->getTraceAsString(),
            ]);
        } catch (\Throwable) {
            // Even logging failed — proceed to the static response anyway.
        }

        $body = [
            'success' => false,
            'message' => 'Internal server error',
            'data'    => [],
            'errors'  => [
                'runtime_error' => class_basename($original) . ': ' . $original->getMessage(),
            ],
        ];

        try {
            if (config('app.debug')) {
                $body['trace'] = [
                    'file'      => $original->getFile(),
                    'line'      => $original->getLine(),
                    'class'     => get_class($original),
                    'secondary' => $secondary ? get_class($secondary) : null,
                ];
            }
        } catch (\Throwable) {
            // config() unavailable — no debug trace, proceed.
        }

        return response()->json($body, $status);
    }
}
