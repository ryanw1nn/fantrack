<?php

namespace SynergyERP\Shared\Handlers\Queries;

use SynergyERP\Shared\Handlers\BaseHandler;

/**
 * FetchQueryHandler
 *
 * Resolves the target row by whichever identifier the client sent — in
 * priority order (public_id → public_ref → id) — so any one correct key
 * finds the record. public_id wins because it's the stable, client-facing
 * identity the frontend navigates by; a cache-warmed BIGINT `id` that's
 * drifted (e.g. after a reseed) would otherwise force a false 404 when the
 * list cache's id no longer matches the row public_id points to. Falls
 * back to id only when neither public identifier is present.
 */
class FetchQueryHandler extends BaseHandler
{
    public function handle(): array
    {
        $data  = $this->getValidatedData();
        $model = $this->getNewModel();
        $query = $model->newQuery();

        // Priority-based: the stable, client-owned key wins. A client
        // that sends both {public_id, id} no longer 404s when the cached
        // id doesn't match the row public_id points to.
        if (!empty($data['public_id'])) {
            $query->where('public_id', $data['public_id']);
        } elseif (!empty($data['public_ref'])) {
            $query->where('public_ref', $data['public_ref']);
        } elseif (!empty($data['id'])) {
            $query->where('id', $data['id']);
        } else {
            throw new \LogicException('Fetch contract passed no identifier.');
        }

        $result = $query->first();
        if (!$result) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Record not found');
        }

        return [$result];
    }
}