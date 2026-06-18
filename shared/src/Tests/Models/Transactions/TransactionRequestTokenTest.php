<?php

namespace SynergyERP\Shared\Tests\Models\Transactions;

use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;

/**
 * Covers TransactionRequest's JWT-claim extraction contract after the
 * `tenant_id`/`principal_id` → `tenant_puid`/`principal_puid` rename:
 *   - Canonical puid claims populate the accessors.
 *   - Legacy id/key claims are ignored (null accessors).
 *   - When no token is present, the X-Principal-Puid header wins.
 */
final class TransactionRequestTokenTest extends TestCase
{
    private const SECRET = 'test-secret-transaction-request';
    private const OPERATION_KEY = 'auth-service.command.user.create';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('JWT_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        parent::tearDown();
    }

    public function test_puid_claims_populate_accessors(): void
    {
        $token = $this->signToken([
            'iss'            => 'auth-service',
            'email'          => 'ryan@example.com',
            'schema'         => 'rrglasswindows',
            'tenant_puid'    => '01HTNNT0000000000000000001',
            'principal_puid' => '01HTNNPRIN000000000000PRIN',
            'delegated_puid' => '01HTNNDELE000000000000DELE',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $transactionRequest = $this->buildTransactionRequest($token);

        $this->assertSame('01HTNNT0000000000000000001', $transactionRequest->getTenantPuid());
        $this->assertSame('01HTNNPRIN000000000000PRIN', $transactionRequest->getPrincipalPuid());
        $this->assertSame('01HTNNDELE000000000000DELE', $transactionRequest->getDelegatedPuid());
        $this->assertSame('rrglasswindows', $transactionRequest->getSchema());
    }

    public function test_legacy_id_claims_are_ignored_after_clean_swap(): void
    {
        $token = $this->signToken([
            'iss'          => 'auth-service',
            'email'        => 'ryan@example.com',
            'schema'       => 'rrglasswindows',
            'tenant_id'    => '01HTNNT0000000000000000001',
            'principal_id' => '01HTNNPRIN000000000000PRIN',
            'delegated_id' => '01HTNNDELE000000000000DELE',
            'iat'          => time(),
            'exp'          => time() + 3600,
        ]);

        $transactionRequest = $this->buildTransactionRequest($token);

        $this->assertNull($transactionRequest->getTenantPuid());
        $this->assertNull($transactionRequest->getPrincipalPuid());
        $this->assertNull($transactionRequest->getDelegatedPuid());
    }

    public function test_legacy_key_claims_are_ignored_after_clean_swap(): void
    {
        $token = $this->signToken([
            'iss'           => 'auth-service',
            'email'         => 'ryan@example.com',
            'schema'        => 'rrglasswindows',
            'tenant_key'    => '01HTNNT0000000000000000001',
            'principal_key' => '01HTNNPRIN000000000000PRIN',
            'delegated_key' => '01HTNNDELE000000000000DELE',
            'iat'           => time(),
            'exp'           => time() + 3600,
        ]);

        $transactionRequest = $this->buildTransactionRequest($token);

        $this->assertNull($transactionRequest->getTenantPuid());
        $this->assertNull($transactionRequest->getPrincipalPuid());
        $this->assertNull($transactionRequest->getDelegatedPuid());
    }

    public function test_header_fallback_populates_principal_when_no_token(): void
    {
        $request = new Request();
        $request->headers->set('X-Tenant-Schema', 'rrglasswindows');
        $request->headers->set('X-Principal-Puid', '01HTNNPRIN000000000000PRIN');

        $transactionRequest = new TransactionRequest(
            $request,
            new OperationKeyContext(self::OPERATION_KEY)
        );

        $this->assertSame('01HTNNPRIN000000000000PRIN', $transactionRequest->getPrincipalPuid());
        $this->assertSame('rrglasswindows', $transactionRequest->getSchema());
        $this->assertNull($transactionRequest->getTenantPuid());
        $this->assertNull($transactionRequest->getDelegatedPuid());
    }

    private function signToken(array $payload): string
    {
        return JWT::encode($payload, self::SECRET, 'HS256');
    }

    private function buildTransactionRequest(string $token): TransactionRequest
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return new TransactionRequest(
            $request,
            new OperationKeyContext(self::OPERATION_KEY)
        );
    }
}
