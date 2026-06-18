<?php

namespace SynergyERP\Shared\Models\Base;

/**
 * Base class for auth-service models. Schema defaults to `accounts` —
 * the master auth schema shared across all tenants — so callers do not
 * need to prime the model via any setTransactionSchema()-style call
 * before reading or writing. Subclasses can still override `$schema`
 * if a particular auth-adjacent table lives somewhere else.
 */
class AuthModel extends SystemModel
{
    protected $schema = 'accounts';
}
