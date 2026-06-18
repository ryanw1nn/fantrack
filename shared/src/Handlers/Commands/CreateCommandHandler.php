<?php

namespace SynergyERP\Shared\Handlers\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Handlers\Commands\BaseCommandHandler;

final class CreateCommandHandler extends BaseCommandHandler
{
    /**
     * CREATE WORKFLOW
     * Owns the transaction and lifecycle
     */
    public function handle(): array
    {
        $model = $this->getNewModel();
        return DB::transaction(
            function () use ($model) {
                $this->setMetaData($model);
                $this->applyContractToModel($model);
                
                Log::info('CreateCommandHandler: Before save', [
                    'model_class' => get_class($model),
                    'model_data' => $model->toArray(),
                    'model_exists' => $model->exists
                ]);
                
                $saved = $model->save();
                
                Log::info('CreateCommandHandler: After save', [
                    'model_class' => get_class($model),
                    'model_id' => $model->id,
                    'model_exists' => $model->exists,
                    'save_result' => $saved,
                    'model_data' => $model->toArray()
                ]);
                
                return [$model];
            }
        );
    }
}
