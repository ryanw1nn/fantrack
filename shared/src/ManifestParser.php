<?php

namespace SynergyERP\Shared;

/**
 * This class is responsible for parsing the manifest file and extracting the rules for each model in the manifest
 * 
 * @author Alexander Torres
 * @package SynergyERP\Shared
 */
class ManifestParser
{
    private array $manifest;

    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
    }


    public function generateRules(array $model, string $action, string $schema, string $modelName): array
    {
        $rules = [];
        $DBconnection = env('DB_CONNECTION');

        // Effective schema for *this* model's rules. The $schema parameter
        // is the request's tenant schema (TenantStorage / JWT). For
        // AuthModels and any other SystemModel that pins itself with
        // `manifest.models.<X>.eloquent.schema` (e.g. "accounts"), the
        // tenant schema is the wrong target — Tenant lives in `accounts`,
        // not in `rrglasswindows`. Use the manifest-declared schema when
        // present; fall back to the request schema only for TenantModels
        // (eloquent.schema === null / "tenant" / "default"), which
        // legitimately route per-request. Mirrors the same source-of-
        // truth policy ManifestSchemaResolver applies to runtime Eloquent
        // operations on these models.
        $modelSchema = self::normalizeSchemaLabel(
            $this->manifest['models'][$modelName]['eloquent']['schema'] ?? null
        );
        $selfSchema = $modelSchema ?? $schema;

        foreach ($model['members'] as $field => $meta) {


            // we would add rules for the member here 
            $memberRules = [];

            // Skip private and auto-increment members.
            if ($action === 'create' && (($meta['visibility'] ?? null) === 'private' || ($meta['isAutoIncrementing'] ?? false) === true)) {
                continue;
            }

            // Skip manual increment fields for create - they're auto-calculated in afterValidation()
            if ($action === 'create' && ($meta['isManualIncrementing'] ?? false) === true) {
                continue;
            }

            // Skip primary key for create actions - it's auto-generated
            if ($action === 'create' && ($meta['isPrimaryKey'] ?? false) === true) {
                continue;
            }

            // Skip server-set fields on create — these are never accepted from
            // the client. public_id/public_ref/ulid are injected in afterValidation();
            // created_by_principal is injected from the JWT in ContractFactory.
            if ($action === 'create' && in_array($field, ['public_id', 'public_ref', 'ulid', 'created_by_principal'], true)) {
                continue;
            }

            // Required / Nullable
            // A manifest-declared default makes the field optional from the
            // client's perspective — BaseCommandContract::fillManifestDefaults()
            // injects the default into the request before validation, so the
            // rule only has to tolerate a missing / null payload value.
            $hasDefault = ($meta['default'] ?? null) !== null;
            if (($meta['isNullable'] ?? true) === false && $action === 'create' && !$hasDefault) {
                $memberRules[] = 'required';
            } else {
                $memberRules[] = 'nullable';
            }

            if ($action === 'update' && $meta['isPrimaryKey'] === true) {
                // Private pk is never sent by the client; public_id is used for lookup instead.
                if ($meta['visibility'] === 'private') {
                    continue;
                }
                $memberRules[] = 'required';
            }

            // public_id is required on update so UpdateCommandHandler always has a lookup key.
            if ($action === 'update' && $field === 'public_id') {
                $memberRules = array_filter($memberRules, fn($r) => $r !== 'nullable');
                $memberRules[] = 'required';
            }
            // Map SQL types to Laravel validation rules.
            $type = strtoupper($meta['type']);

            if (str_contains($type, 'VARCHAR')) {
                $memberRules[] = 'string';

                if (preg_match('/VARCHAR\((\d+)\)/', $type, $matches)) {
                    $memberRules[] = 'max:' . $matches[1];
                }
            }

            // CHAR fields use `size:` (exact length) instead of `max:`.
            // VARCHAR exclusion required: str_contains('VARCHAR', 'CHAR') is true.
            // Skip `size:` for FK/external-key refs and on delete/update — those
            // use the value as a lookup key where `exists:` is the right constraint.
            if (str_contains($type, 'CHAR') && !str_contains($type, 'VARCHAR')) {
                $memberRules[] = 'string';

                $skipSize = $meta['isForeignKey'] === true
                    || ($meta['isExternalKey'] ?? false) === true
                    || in_array($action, ['delete', 'update'], true);

                if (preg_match('/CHAR\((\d+)\)/', $type, $matches) && !$skipSize) {
                    $memberRules[] = 'size:' . $matches[1];
                }
            }

            if (str_contains($type, 'BIGINT')) {
                $memberRules[] = 'integer';
            }

            if (str_contains($type, 'DECIMAL')) {
                $memberRules[] = 'numeric';
            }

            if (str_contains($type, 'TIMESTAMP')) {
                $memberRules[] = 'date';
            }

            // 

            if(isset($meta['constraints'])) 
            {
                foreach ($meta['constraints'] as $constraint) {
                    $memberRules[] = $constraint;
                }
            }
            
            // Foreign Key Rule
            if (($meta['isForeignKey'] ?? false) === true && isset($meta['relationship'])) {
                $relatedModelName = $meta['relationship']['model'];
                $foreignKey = $meta['relationship']['member'];

                // Get the actual table name from the related model's eloquent config
                $relatedTable = $this->manifest['models'][$relatedModelName]['eloquent']['table'] ?? strtolower($relatedModelName);

                // Cross-schema FKs: AuthModel-derived rows (Principal, Tenant,
                // etc.) live in `accounts`, not the per-request tenant schema.
                // Honour the related model's declared eloquent.schema; fall
                // back to the request schema for tenant-local references
                // (schema is null / "tenant" / "default" — see
                // normalizeSchemaLabel for placeholder semantics).
                $relatedSchema = self::normalizeSchemaLabel(
                    $this->manifest['models'][$relatedModelName]['eloquent']['schema'] ?? null
                );
                $existsSchema = $relatedSchema ?? $schema;

                $memberRules[] = "exists:".$DBconnection.".".$existsSchema.".$relatedTable,$foreignKey";
            }

            // Get the actual table name from the model's eloquent config
            $table = $this->manifest['models'][$modelName]['eloquent']['table'] ?? strtolower($modelName);

            //Primary Key Rule
            if (($meta['isPrimaryKey'] ?? false) === true) {
                // Skip exists validation during testing
                $isTesting = app()->runningUnitTests() ||
                            app()->environment('testing') ||
                            (app()->runningInConsole() && defined('PHPUNIT_COMPOSER_INSTALL'));

                if (!$isTesting) {
                    $memberRules[] = "exists:".$DBconnection.'.'.$selfSchema.'.'.$table.','. $field;
                }
            }
            // Unique Constraint Rule (only for create - updates enforced by DB constraint)
            if (($meta['isUnique'] ?? false) === true && $action === 'create') {
                $memberRules[] = "unique:".$DBconnection.'.'.$selfSchema.'.'.$table.','.$field;
            }

            $rules[$field] = $memberRules;
        }

        return $rules;
    }

    /**
     * Coerce a raw `eloquent.schema` value to either a real schema name or
     * null (= "use the request's tenant schema"). The Python LAF generator
     * (laravel-service-factory/.../sql_generator.py:_MAIN_SCHEMA_LABELS)
     * emits the literal placeholders "tenant" and "default" to mark a
     * model as tenant-routable; the PHP runtime would otherwise treat
     * those as actual database names and produce SQL like
     * `select ... from tenant.channel_types` → 1049 Unknown database.
     */
    private static function normalizeSchemaLabel(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '' || $raw === 'tenant' || $raw === 'default' || $raw === 'service_default') {
            return null;
        }
        return $raw;
    }
}