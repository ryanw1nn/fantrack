<?php

namespace SynergyERP\Shared\Services;

use SynergyERP\Shared\Models\Base\TenantModel;

/**
 * Builds canonical S3 keys for tenant-scoped files.
 *
 * Layout: {tenant_schema}/{ModelClass}/{public_id}/{filename}
 * e.g.   tenant_acme/Estimate/01HXYZABCDEFGHJKMNPQRSTVWX/proposal.pdf
 *
 * Pure class — no Laravel container or request state. Safe to unit test.
 */
class StoragePathBuilder
{
    public function keyFor(TenantModel $model, string $filename): string
    {
        return $this->prefixFor($model) . '/' . $this->sanitizeFilename($filename);
    }

    public function prefixFor(TenantModel $model): string
    {
        // SchemaAwareTrait declares getTransactionSchema() as a static
        // method; the instance-style call here is PHP-legal and keeps the
        // builder indifferent to whether the model is already bound to an
        // instance or just a class reference.
        $schema = $model::getTransactionSchema();
        if ($schema === null || $schema === '') {
            throw new \RuntimeException(
                'StoragePathBuilder: model has no tenant schema set. Call setTransactionSchema() first.'
            );
        }

        $publicId = $model->public_id ?? null;
        if ($publicId === null || $publicId === '') {
            throw new \RuntimeException(
                'StoragePathBuilder: model has no public_id. Persist or assign public_id before building storage key.'
            );
        }

        return sprintf(
            '%s/%s/%s',
            $this->sanitizeSegment($schema),
            $this->modelSegment($model),
            $this->sanitizeSegment($publicId)
        );
    }

    public function sanitizeFilename(string $filename): string
    {
        $base = basename($filename);
        $base = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $base) ?? '';
        $base = trim($base, '._-');

        if ($base === '') {
            throw new \RuntimeException('StoragePathBuilder: filename is empty after sanitization.');
        }

        return $base;
    }

    private function modelSegment(TenantModel $model): string
    {
        $class = (new \ReflectionClass($model))->getShortName();
        return $this->sanitizeSegment($class);
    }

    private function sanitizeSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $segment) ?? '';
        if ($clean === '' || $clean === '_') {
            throw new \RuntimeException('StoragePathBuilder: path segment is empty after sanitization.');
        }
        return $clean;
    }
}
