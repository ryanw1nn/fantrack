<?php

namespace SynergyERP\Shared\Models\Contracts;

/**
 * Marker contract for models whose database schema is bound per-request
 * (typically from the tenant JWT) rather than resolved from the manifest.
 *
 * Implementors are primed by the handler pipeline via setTransactionSchema()
 * before the first query. Models that pin or resolve their schema statically
 * (SystemModel, AuthModel) must NOT implement this interface — their
 * absence is the signal to skip priming.
 *
 * Methods are static to match SchemaAwareTrait, whose schema slot is a
 * per-class static (see the trait PHPDoc for why).
 */
interface SchemaAware
{
    public static function setTransactionSchema(string $schema): void;

    public static function getTransactionSchema(): ?string;
}
