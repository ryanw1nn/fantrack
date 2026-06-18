<?php

namespace SynergyERP\Shared\Tests;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Handlers\BaseHandler;
use SynergyERP\Shared\Models\Contracts\SchemaAware;
use SynergyERP\Shared\Models\Traits\SchemaAwareTrait;

/**
 * Covers BaseHandler::getNewModel()'s schema-priming guard:
 *   - SchemaAware models are primed with the contract's tenant schema,
 *   - non-SchemaAware models flow through untouched and do NOT throw
 *     (the original Ulid regression).
 *
 * Stays PHPUnit-only; no Laravel boot required.
 */
final class BaseHandlerSchemaGuardTest extends TestCase
{
    public function test_get_new_model_primes_schema_for_schema_aware_models(): void
    {
        $schemaAwareClass = get_class(new class extends Model implements SchemaAware {
            use SchemaAwareTrait;
        });

        $handler = $this->buildHandler($schemaAwareClass, 'rrglasswindows');

        $model = $handler->getNewModel();

        $this->assertInstanceOf($schemaAwareClass, $model);
        $this->assertSame('rrglasswindows', $schemaAwareClass::getTransactionSchema());
    }

    public function test_get_new_model_skips_priming_for_non_schema_aware_models(): void
    {
        // A plain Eloquent model — representative of AuthModel/SystemModel,
        // which do not implement SchemaAware.
        $plainClass = get_class(new class extends Model {
            protected $table = 'fakes';
        });

        $handler = $this->buildHandler($plainClass, 'rrglasswindows');

        // The original bug: applyTenantSchema typehinted TenantModel and
        // threw TypeError on AuthModel subclasses. The guard must make
        // this call a no-op.
        $model = $handler->getNewModel();

        $this->assertInstanceOf($plainClass, $model);
    }

    public function test_get_new_model_respects_should_apply_flag(): void
    {
        $schemaAwareClass = get_class(new class extends Model implements SchemaAware {
            use SchemaAwareTrait;
        });

        $handler = $this->buildHandler($schemaAwareClass, 'rrglasswindows');

        $handler->getNewModel(false);

        $this->assertNull($schemaAwareClass::getTransactionSchema());
    }

    private function buildHandler(string $modelClass, string $tenantSchema): BaseHandler
    {
        $contract = $this->getMockBuilder(Contract::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $prop = (new ReflectionClass(Contract::class))->getProperty('tenantSchema');
        $prop->setAccessible(true);
        $prop->setValue($contract, $tenantSchema);

        return new class($contract, $modelClass, 'test-principal') extends BaseHandler {
            public function handle(): array
            {
                return [];
            }
        };
    }
}
