<?php

namespace SynergyERP\Shared\Models\Transactions;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Transaction error handler class for standardized error processing
 * Provides consistent error handling and formatting across the application
 * Accepts Throwable to handle both exceptions and errors
 */
class TransactionError
{
    protected $code = 500;
    
    protected $message = 'An unexpected error occurred';
    
    protected $type = 'ServerError';
    
    protected $errors = [];
    
    protected $throwable;
    
    protected $data = [];

    public function __construct(\Throwable $throwable)
    {
        $this->throwable = $throwable;
        $this->processThrowable();
    }
    
    /**
     * Process the throwable and set appropriate properties
     */
    protected function processThrowable(): void
    {
        Log::error('Throwable caught: ' . $this->throwable->getMessage(), [
            'throwable' => get_class($this->throwable),
            'file' => $this->throwable->getFile(),
            'line' => $this->throwable->getLine(),
            'trace' => $this->throwable->getTraceAsString()
        ]);
        
        $throwableClass = get_class($this->throwable);
        
        switch (true) {
            case $this->throwable instanceof ValidationException:
                $this->handleValidationException();
                break;                
            case $this->throwable instanceof \PDOException:
            case $this->throwable instanceof QueryException:
                $this->handleDatabaseException();
                break;                
            case $this->throwable instanceof ModelNotFoundException:
                $this->handleModelNotFoundException();
                break;
            case $this->throwable instanceof \ErrorException:
                $this->handleErrorException();
                break;
            case $this->throwable instanceof \TypeError:
                $this->handleTypeError();
                break;
            case $this->throwable instanceof \LogicException:
                $this->handleLogicException();
                break;
            case $this->throwable instanceof \RuntimeException:
                $this->handleRuntimeException();
                break;
            default:
                $this->handleGenericThrowable();
                break;
        }
    }
    
    /**
     * Handle validation exceptions
     */
    protected function handleValidationException(): void
    {
        $this->code = 422;
        $this->type = 'ValidationError';
        $this->message = 'Validation failed';
        
        if ($this->throwable instanceof ValidationException && method_exists($this->throwable, 'errors')) {
            $this->errors = $this->throwable->errors();
        }
    }
    
    /**
     * Handle database exceptions
     */
    protected function handleDatabaseException(): void
    {
        $this->code = 500;
        $this->type = 'DatabaseError';
        $this->message = 'A database error occurred';
        
        if (env('APP_ENV') !== 'production') {
            $this->data = [
                'sqlState' => $this->throwable->getCode(),
                'errorInfo' => $this->throwable->errorInfo ?? null
            ];
        }
    }
    
    /**
     * Handle model not found exceptions
     */
    protected function handleModelNotFoundException(): void
    {
        $this->code = 404;
        $this->type = 'NotFound';
        $this->message = 'The requested resource was not found';
        $this->errors = ['resource' => 'The requested resource does not exist or has been deleted'];
    }
    
    /**
     * Handle PHP error exceptions
     */
    protected function handleErrorException(): void
    {
        $this->code = 500;
        $this->type = 'ServerError';
        $this->message = 'An internal server error occurred';
        
        if (env('APP_ENV') !== 'production') {
            $this->data = [
                'error_message' => $this->throwable->getMessage(),
                'error_file' => $this->throwable->getFile(),
                'error_line' => $this->throwable->getLine()
            ];
        }
    }
    
    /**
     * Handle type errors (common in model operations)
     */
    protected function handleTypeError(): void
    {
        $this->code = 500;
        $this->type = 'ServerError';
        $this->message = 'A type error occurred while processing your request';
        
        $errorMsg = $this->throwable->getMessage();
        if (strpos($errorMsg, 'must be of type') !== false) {
            $this->errors = ['type_error' => 'Invalid data type provided'];
        }
        
        if (env('APP_ENV') !== 'production') {
            $this->data = ['error_message' => $errorMsg];
        }
    }
    
    /**
     * Handle logic exceptions
     */
    protected function handleLogicException(): void
    {
        $this->code = 400;
        $this->type = 'LogicError';
        $this->message = 'A logic error occurred while processing your request';
        $this->errors = ['logic_error' => $this->throwable->getMessage()];
    }
    
    /**
     * Handle runtime exceptions
     */
    protected function handleRuntimeException(): void
    {
        $message = $this->throwable->getMessage();
        
        // Missing file errors = 503 Service Unavailable
        if (strpos($message, 'File does not exist') !== false || 
            strpos($message, 'manifest.json') !== false ||
            strpos($message, 'Directory does not exist') !== false) {
            $this->code = 503;
            $this->type = 'ServiceUnavailable';
            $this->message = 'Required system file is missing';
        }
        // Configuration errors = 400 Bad Request
        else if (strpos($message, 'not configured') !== false ||
                 strpos($message, 'Manifest path not configured') !== false) {
            $this->code = 400;
            $this->type = 'ConfigurationError';
            $this->message = 'System configuration error';
        }
        // All other RuntimeExceptions = 500 Server Error
        else {
            $this->code = 500;
            $this->type = 'ServerError';
            $this->message = 'An unexpected runtime error occurred';
        }
        
        $this->errors = ['runtime_error' => $this->throwable->getMessage()];
        
        if (env('APP_ENV') !== 'production') {
            $this->data = [
                'error_message' => $this->throwable->getMessage(),
                'error_file' => $this->throwable->getFile(),
                'error_line' => $this->throwable->getLine()
            ];
        }
    }
    
    /**
     * Handle generic throwables
     */
    protected function handleGenericThrowable(): void
    {
        $this->code = 500;
        $this->type = 'ServerError';
        $this->message = $this->throwable->getMessage() ?: 'An unexpected error occurred';
        
        if ($this->throwable instanceof \InvalidArgumentException) {
            $this->code = 400;
            $this->type = 'BadRequest';
        } elseif ($this->throwable instanceof UnauthorizedHttpException) {
            $this->code = 401;
            $this->type = 'Unauthorized';
        } elseif ($this->throwable instanceof AccessDeniedHttpException) {
            $this->code = 403;
            $this->type = 'Forbidden';
        } elseif ($this->throwable instanceof NotFoundHttpException) {
            $this->code = 404;
            $this->type = 'NotFound';
        } elseif (strpos(get_class($this->throwable), 'Illuminate\Database') !== false) {
            $this->handleDatabaseException();
        }
    }
    
    /**
     * Get the HTTP status code
     *
     * @return int
     */
    public function getCode(): int
    {
        return $this->code;
    }
    
    /**
     * Set the HTTP status code
     *
     * @param int $code
     * @return self
     */
    public function setCode(int $code): self
    {
        $this->code = $code;
        return $this;
    }
    
    /**
     * Get the error message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }
    
    /**
     * Set the error message
     *
     * @param string $message
     * @return self
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }
    
    /**
     * Get the error type
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }
    
    /**
     * Set the error type
     *
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }
    
    /**
     * Get validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Set validation errors
     *
     * @param array $errors
     * @return self
     */
    public function setErrors(array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }
    
    /**
     * Get additional data related to the error
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * Set additional data related to the error
     *
     * @param array $data
     * @return self
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }
    
    /**
     * Get the original throwable
     *
     * @return \Throwable
     */
    public function getThrowable(): \Throwable
    {
        return $this->throwable;
    }
    
    /**
     * Get the original throwable (alias for backward compatibility)
     * This allows TransactionError to be used interchangeably with legacy code
     *
     * @return \Throwable
     */
    public function getException(): \Throwable
    {
        return $this->throwable;
    }
}
