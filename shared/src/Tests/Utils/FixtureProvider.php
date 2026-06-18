<?php

namespace SynergyERP\Shared\Tests\Utils;

final class FixtureProvider
{
    public static function loadDir(string $dir): \Generator
    {
        //$abs = self::toAbsolutePath($dir);

        if (!is_dir($dir)) {
            throw new \RuntimeException("Fixture directory does not exist: {$dir}");
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $filePath) {
            $name = basename($filePath, '.json');
            $json = JsonFileLoader::load($filePath);
            yield $name => [$json, $name];
        }
    }

    private static function toAbsolutePath(string $dir): string
    {
        // If already absolute, keep it
        $isAbsolute =
            str_starts_with($dir, DIRECTORY_SEPARATOR) ||
 
            preg_match('/^[A-Za-z]:\\\\/', $dir) === 1;

        if ($isAbsolute) {
            return rtrim($dir, DIRECTORY_SEPARATOR);
        }

        // Otherwise resolve relative to project root (adjust depth if needed)
        $root = realpath(__DIR__ . '/../../../');
        if ($root === false) {
            throw new \RuntimeException('Unable to resolve project root.');
        }

        return rtrim($root . DIRECTORY_SEPARATOR . $dir, DIRECTORY_SEPARATOR);
    }
}