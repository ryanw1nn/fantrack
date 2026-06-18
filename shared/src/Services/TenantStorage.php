<?php

namespace SynergyERP\Shared\Services;

use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use SynergyERP\Shared\Models\Base\TenantModel;

/**
 * Tenant-aware object storage.
 *
 * All writes/reads resolve to:
 *   {tenant_schema}/{ModelClass}/{public_id}/{filename}
 *
 * Backed by the "s3-tenant" filesystem disk registered in SharedServiceProvider.
 * Every call site MUST go through this service — never call Storage::disk('s3-tenant')
 * with a raw path, or the tenant isolation invariant is broken.
 *
 * Dual endpoint: server-side ops (put/get/exists/delete) use the disk's
 * `endpoint` — typically a container-network hostname like
 * `http://object-storage:9000` in dev. Presigned URLs are signed against
 * `public_endpoint` (e.g. `http://localhost:7500`) so the browser can
 * reach the object store on the host. If `public_endpoint` is null the
 * regular `endpoint` is used for both.
 */
class TenantStorage
{
    public const DISK = 's3-tenant';
    public const DEFAULT_PRESIGN_TTL_SECONDS = 900;

    private ?S3Client $presigner = null;

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly StoragePathBuilder $paths,
    ) {
    }

    public function put(TenantModel $model, string $filename, string|\Psr\Http\Message\StreamInterface $contents): string
    {
        $key = $this->paths->keyFor($model, $filename);
        $this->disk()->put($key, $contents);
        return $key;
    }

    public function get(TenantModel $model, string $filename): ?string
    {
        return $this->disk()->get($this->paths->keyFor($model, $filename));
    }

    public function exists(TenantModel $model, string $filename): bool
    {
        return $this->disk()->exists($this->paths->keyFor($model, $filename));
    }

    public function delete(TenantModel $model, string $filename): bool
    {
        return $this->disk()->delete($this->paths->keyFor($model, $filename));
    }

    public function deleteAll(TenantModel $model): bool
    {
        return $this->disk()->deleteDirectory($this->paths->prefixFor($model));
    }

    public function presignedUploadUrl(
        TenantModel $model,
        string $filename,
        int $ttlSeconds = self::DEFAULT_PRESIGN_TTL_SECONDS,
        array $options = [],
    ): string {
        $key = $this->paths->keyFor($model, $filename);

        $args = [
            'Bucket' => $this->bucket(),
            'Key'    => $key,
        ];
        if (!empty($options['ContentType'])) {
            $args['ContentType'] = $options['ContentType'];
        }

        $command = $this->presigner()->getCommand('PutObject', $args);
        $request = $this->presigner()->createPresignedRequest(
            $command,
            '+' . $ttlSeconds . ' seconds',
        );

        return (string) $request->getUri();
    }

    public function presignedDownloadUrl(
        TenantModel $model,
        string $filename,
        int $ttlSeconds = self::DEFAULT_PRESIGN_TTL_SECONDS,
    ): string {
        $key = $this->paths->keyFor($model, $filename);

        $command = $this->presigner()->getCommand('GetObject', [
            'Bucket' => $this->bucket(),
            'Key'    => $key,
        ]);
        $request = $this->presigner()->createPresignedRequest(
            $command,
            '+' . $ttlSeconds . ' seconds',
        );

        return (string) $request->getUri();
    }

    public function keyFor(TenantModel $model, string $filename): string
    {
        return $this->paths->keyFor($model, $filename);
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk(self::DISK);
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.' . self::DISK . '.bucket');
    }

    /**
     * Standalone S3 client whose endpoint is the browser-reachable URL.
     * Only used for signing — never actually connects, so not having
     * network access to this endpoint from the PHP container is fine.
     */
    private function presigner(): S3Client
    {
        if ($this->presigner !== null) {
            return $this->presigner;
        }

        $disk = (string) self::DISK;
        $config = (array) config('filesystems.disks.' . $disk);

        $endpoint = $config['public_endpoint'] ?? $config['endpoint'] ?? null;

        $args = [
            'version'                 => 'latest',
            'region'                  => $config['region'] ?? 'us-east-1',
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'credentials'             => [
                'key'    => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
        ];
        if (!empty($endpoint)) {
            $args['endpoint'] = $endpoint;
        }

        return $this->presigner = new S3Client($args);
    }
}
