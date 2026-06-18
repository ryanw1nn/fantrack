<?php

namespace SynergyERP\Shared\Handlers\Commands;

use Illuminate\Support\Facades\DB;
use SynergyERP\Shared\Handlers\Commands\BaseCommandHandler;
final class UpdateCommandHandler extends BaseCommandHandler
{
    /**
     * UPDATE WORKFLOW
     * Single transaction owner
     * Tenant context first
     */
    public function handle(): array
    {
        $data = $this->getValidatedData();
        // Models with a private pk send public_id for lookup; public-pk models (e.g. Bid) send id.
        $model = isset($data['id'])
            ? $this->getModelById($data['id'])
            : $this->getModelByPublicId($data['public_id']);

        if (isset($model->is_mutable) && $model->is_mutable === false) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'This record is immutable and cannot be updated.');
        }

        return DB::transaction(function () use ($model, $data) {
            // If the model uses optimistic locking and a version was provided,
            // use the lock-aware update
            if (
                method_exists($model, 'updateWithLock')
                && isset($data['version'])
            ) {
                $updateData = array_diff_key($data, array_flip(['id', 'version']));
                $model->updateWithLock((int) $data['version'], $updateData);
            } else {
                $this->applyContractToModel($model);
                $model->save();
            }
            return [$model];
        }); 
    }
}