<?php

namespace SynergyERP\Shared\Models\Transactions;

use Illuminate\Support\{
    Facades\Log,
    Facades\Env,
};
use Illuminate\Http\JsonResponse;
use SynergyERP\Shared\Models\Transactions\TransactionError;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Models\Base\TransactionModel;


/**
 * Base CommandHandler class for all command handlers in the system
 * Implements common functionality for command processing in CQRS pattern
 */
class TransactionResponse extends TransactionModel
{
    private int $code;
    private bool $success;
    private string $message;
    private array $handlerResult;
    private array $errors;
    private OperationKeyContext $operationKeyContext;
    private ?TransactionModel $issue;
    private ?TransactionError $error;

    public function __construct(
        OperationKeyContext $operationKeyContext,
        array $handlerResult,
        TransactionError|null $issue = null)
    {
        $this->operationKeyContext = $operationKeyContext;
        $this->handlerResult = $handlerResult;
        $this->issue = null;
        $this->error = null;

        if ($issue instanceof TransactionError) {
            $this->error = $issue;
            $originalThrowable = $issue->getThrowable();
            Log::error('TransactionResponse: Error detected', [
                'throwable' => get_class($originalThrowable),
                'message' => $originalThrowable->getMessage(),
                'file' => $originalThrowable->getFile(),
                'line' => $originalThrowable->getLine(),
                'operation' => $operationKeyContext->getOperation(),
                'trace' => $originalThrowable->getTraceAsString()
            ]);
        }
        $this->generateResponse();
    }

    public function getOutput(): array
    {
        // structure response
        $response = [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->getData(),
        ];

        // Only include errors key when success is false
        if (!$this->success) {
            $response['errors'] = $this->errors;
            
            if ($this->error) {
                $originalThrowable = $this->error->getException();
                if ($originalThrowable) {
                    $response['trace'] = [
                        'file' => $originalThrowable->getFile(),
                        'line' => $originalThrowable->getLine(),
                        'class' => get_class($originalThrowable),
                        'message' => $originalThrowable->getMessage()
                    ];
                    
                    if (env('APP_ENV') !== 'production') {
                        $response['trace']['stack'] = $originalThrowable->getTraceAsString();
                    }
                }
            }
        }

        return $response;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getData(): array
    {        
        return $this->handlerResult;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function generateResponse()
    {
        Log::info('TransactionResponse: generateResponse called', [
            'has_error' => $this->error !== null,
            'error_type' => $this->error ? get_class($this->error) : null,
            'error_code' => $this->error ? $this->error->getCode() : null
        ]);
        
        if ($this->error && $this->error->getCode() != 200) {
            $this->code = $this->error->getCode();
            $this->success = false;
            $this->message = $this->error->getMessage();
            $this->errors = $this->error->getErrors();
            
            Log::info('TransactionResponse: Error response generated', [
                'code' => $this->code,
                'message' => $this->message,
                'errors' => $this->errors
            ]);
        } else {
            $this->code = 200;
            $this->success = true;
            $this->message = $this->generateSuccessMessage();
            $this->errors = [];
            
            Log::info('TransactionResponse: Success response generated', [
                'code' => $this->code,
                'message' => $this->message,
                'error_was_null' => $this->error === null,
                'error_code_was_200' => $this->error ? $this->error->getCode() === 200 : false
            ]);
        }
    }

    private function generateSuccessMessage()
    {
        $action = ucfirst($this->operationKeyContext->getOperationComponent('action'));
        $model = ucfirst($this->operationKeyContext->getOperationComponent('model'));
        $cqrs = ucfirst($this->operationKeyContext->getOperationComponent('cqrs'));
        return "Successfully handled {$action}{$model}{$cqrs} request.";
    }
    
    /**
     * Create a response object with the formatted result
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toClientResponse(): JsonResponse
    {
        // Return the output using the structure defined in getOutput
        return response()->json($this->getOutput(), $this->getCode());
    }
}
