<?php

namespace SynergyERP\Shared\Models\Traits;

use Symfony\Component\Uid\Ulid;

/**
 * Auto-assigns a 26-char ULID to the `public_id` column on model creation
 * when the caller has not set one explicitly.
 *
 * Matches the manifest convention: public_id is CHAR(26), unique, fillable.
 */
trait HasUlidPublicId
{
    public static function bootHasUlidPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) new Ulid();
            }
        });
    }
}
