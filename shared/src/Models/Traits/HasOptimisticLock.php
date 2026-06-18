<?php

namespace SynergyERP\Shared\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Provides optimistic locking via a version column.
 * 
 * On save, the version is incremented. If the version in the DB
 * doesn't match the expected version, a 409 Conflict is thrown.
 * 
 * @author Ryan Winn
 * @package SynergyERP\Shared\Models\Traits
 */
trait HasOptimisticLock
{
    /**
     * Boot the trait - register saving event to enforce version check
     */
    public static function bootHasOptimisticLock(): void
    {
        static::updating(function ($model) {
            if (!$model->isDirty('version')) {
                // The caller didn't set a version - auto-increment
                $model->version = ($model->getOriginal('version') ?? 0) + 1;
            }
        });
    }

    /**
     * Perform an optimistic-lock-aware update.
     * Only updates the row if the version matches the expected version.
     * 
     * @param int $expectedVersion  The version the client last saw
     * @param array $attributes     Attributes to update
     * @return bool True if the update succeeded
     * @throws ConflictHttpException If the version doesn't match (409)
     */
    public function updateWithLock(int $expectedVersion, array $attributes): bool
    {
        $affected = static::where('id', $this->id)
            ->where('version', $expectedVersion)
            ->update(array_merge($attributes, [
                'version' => $expectedVersion + 1,
            ]));

        if ($affected === 0) {
            throw new ConflictHttpException(
                "Conflict: resource has been modified by another request. "
                . "Expected version {$expectedVersion}, but the record has been updated. "
                . "Please refresh and retry."
            );
        }

        // Refresh the model to reflect the new state
        $this->refresh();
        return true;
    }

    /**
     * Get the current version.
     */
    public function getVersion(): int
    {
        return $this->version ?? 1;
    }
}