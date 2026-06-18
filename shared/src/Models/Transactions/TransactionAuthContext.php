<?php

namespace SynergyERP\Shared\Models\Transactions;

use Illuminate\Support\{
    Facades\Log,
    Facades\Env
};

/**
 * Base CommandHandler class for all command handlers in the system
 * Implements common functionality for command processing in CQRS pattern
 */
class TransactionAuthContext
{
    public function __construct()
    {
    }
}