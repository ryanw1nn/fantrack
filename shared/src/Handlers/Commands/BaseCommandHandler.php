<?php

namespace SynergyERP\Shared\Handlers\Commands;

use Illuminate\Database\Eloquent\Model;
use SynergyERP\Shared\Contracts\Commands\BaseCommandContract;
use SynergyERP\Shared\Handlers\BaseHandler;
use SynergyERP\Shared\ManifestLoader;

abstract class BaseCommandHandler extends BaseHandler
{

    protected function applyContractToModel(Model $model): void
    {
        $model->fill($this->getValidatedData());
    }

    protected function setMetaData(Model $model): void
    {
        // Source of truth is the manifest, not the live DB schema.
        // Schema::hasColumn() runs against the connection's selected DB,
        // which for AuthModels (in `accounts`) doesn't match the
        // request's tenant schema — the lookup would silently miss the
        // column and skip the stamp. Reading the manifest avoids that
        // cross-schema mismatch and matches the contract's view.
        $modelName = class_basename($model);
        $manifest = ManifestLoader::load()->getManifest();
        $members = $manifest['models'][$modelName]['members'] ?? [];

        if (isset($members['created_by_principal'])) {
            $puid = $this->getPrincipalPuid();
            if ($puid !== null) {
                $member = $members['created_by_principal']['relationship']['member'] ?? null;
                if ($member === 'id') {
                    $id = BaseCommandContract::resolvePrincipalIdFromPuid($puid);
                    if ($id !== null) {
                        $model->created_by_principal = $id;
                    }
                } else {
                    $model->created_by_principal = $puid;
                }
            }
        }

        if (isset($members['created_at'])) {
            $model->created_at = now();
        }
    }
}
