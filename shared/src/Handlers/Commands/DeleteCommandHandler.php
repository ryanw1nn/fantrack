<?php

namespace SynergyERP\Shared\Handlers\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * DeleteCommandHandler
 *
 * Resolves the target row by whichever identifier the client sent — in
 * priority order (id → public_id → public_ref) — so any one correct key
 * finds the record. Using priority rather than chained AND avoids a false
 * miss when an id that's been re-used across test resets or a stale cache
 * disagrees with a fresh public_id.
 *
 * Idempotent for soft-deleted records: if the target is already trashed,
 * return the existing row without re-deleting. A second Delete click on
 * a stale UI succeeds quietly instead of 404-ing.
 *
 * For TenantModel (which uses SoftDeletes), $model->delete() sets
 * deleted_at; other base classes hard-delete.
 */
final class DeleteCommandHandler extends BaseCommandHandler
{
    public function handle(): array
    {
        $data  = $this->getValidatedData();
        [$model, $alreadyTrashed] = $this->resolveTarget($data);

        if ($alreadyTrashed) {
            // Already soft-deleted — idempotent no-op.
            return [$model];
        }

        if (isset($model->is_mutable) && $model->is_mutable === false) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'This record is immutable and cannot be deleted.');
        }

        return DB::transaction(function () use ($model) {
            $model->delete();
            return [$model];
        });
    }

    /**
     * @return array{0: Model, 1: bool} [model, alreadyTrashed]
     */
    private function resolveTarget(array $data): array
    {
        $base  = $this->getNewModel();
        $usesSoftDeletes = in_array(
            SoftDeletes::class,
            class_uses_recursive($base),
            true,
        );
        // Include trashed rows so a second Delete on an already-soft-deleted
        // record resolves (we'll return it idempotently) instead of 404-ing.
        $query = $usesSoftDeletes ? $base->newQueryWithoutScopes() : $base->newQuery();

        // Priority-based: any one correct key locates the row. Use the most
        // specific available key first.
        if (!empty($data['id'])) {
            $query->where('id', $data['id']);
        } elseif (!empty($data['public_id'])) {
            $query->where('public_id', $data['public_id']);
        } elseif (!empty($data['public_ref'])) {
            $query->where('public_ref', $data['public_ref']);
        } else {
            throw new \LogicException('Delete contract passed no identifier.');
        }

        $model = $query->first();
        if (!$model) {
            throw new ModelNotFoundException('Record not found');
        }

        $alreadyTrashed = $usesSoftDeletes
            && method_exists($model, 'trashed')
            && $model->trashed();

        return [$model, $alreadyTrashed];
    }
}
