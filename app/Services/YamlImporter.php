<?php

namespace App\Services;

use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Storage\StorageClient;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Service for importing data from YAML files with environment variable substitution.
 * Supports loading from local filesystem or Google Cloud Storage (gs:// URLs).
 */
class YamlImporter
{
    private ?StorageClient $storageClient = null;

    /**
     * Parse a YAML file and return its contents as an array.
     *
     * @param string $filePath Path to the YAML file (absolute, relative to base_path, or gs:// URL)
     * @return array Parsed YAML contents
     * @throws \RuntimeException If file cannot be read or parsed
     */
    public function parseFile(string $filePath): array
    {
        $content = $this->getFileContent($filePath);

        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new \RuntimeException("Failed to parse YAML file {$filePath}: " . $e->getMessage());
        }

        return $data ?? [];
    }

    /**
     * Get file content from local filesystem or GCS.
     *
     * @param string $path The file path (can be absolute, relative, or gs:// URL)
     * @return string File contents
     * @throws \RuntimeException If file cannot be read
     */
    private function getFileContent(string $path): string
    {
        if ($this->isGcsUrl($path)) {
            return $this->downloadFromGcs($path);
        }

        $absolutePath = $this->resolveFilePath($path);

        if (!file_exists($absolutePath)) {
            throw new \RuntimeException("YAML file not found: {$absolutePath}");
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read YAML file: {$absolutePath}");
        }

        return $content;
    }

    /**
     * Check if a path is a Google Cloud Storage URL.
     *
     * @param string $path The path to check
     * @return bool True if the path is a gs:// URL
     */
    private function isGcsUrl(string $path): bool
    {
        return str_starts_with($path, 'gs://');
    }

    /**
     * Download content from Google Cloud Storage.
     *
     * @param string $gcsUrl The GCS URL (gs://bucket-name/path/to/file.yaml)
     * @return string File contents
     * @throws \RuntimeException If download fails
     */
    private function downloadFromGcs(string $gcsUrl): string
    {
        $parsed = $this->parseGcsUrl($gcsUrl);

        try {
            $storage = $this->getStorageClient();
            $bucket = $storage->bucket($parsed['bucket']);
            $object = $bucket->object($parsed['path']);

            if (!$object->exists()) {
                throw new \RuntimeException("GCS object not found: {$gcsUrl}");
            }

            return $object->downloadAsString();
        } catch (NotFoundException $e) {
            throw new \RuntimeException("GCS object not found: {$gcsUrl}");
        } catch (ServiceException $e) {
            throw new \RuntimeException("GCS error for {$gcsUrl}: " . $e->getMessage());
        }
    }

    /**
     * Parse a GCS URL into bucket and path components.
     *
     * @param string $url The GCS URL (gs://bucket-name/path/to/file.yaml)
     * @return array{bucket: string, path: string} Parsed URL components
     * @throws \RuntimeException If URL format is invalid
     */
    private function parseGcsUrl(string $url): array
    {
        // gs://bucket-name/path/to/file.yaml
        $withoutScheme = substr($url, 5); // Remove "gs://"
        $slashPos = strpos($withoutScheme, '/');

        if ($slashPos === false) {
            throw new \RuntimeException("Invalid GCS URL (missing path): {$url}");
        }

        $path = substr($withoutScheme, $slashPos + 1);
        if (empty($path)) {
            throw new \RuntimeException("Invalid GCS URL (empty path): {$url}");
        }

        return [
            'bucket' => substr($withoutScheme, 0, $slashPos),
            'path' => $path,
        ];
    }

    /**
     * Get the Google Cloud Storage client.
     *
     * Uses GOOGLE_APPLICATION_CREDENTIALS environment variable for authentication,
     * or falls back to default credentials (e.g., attached service account on GCE/Cloud Run).
     *
     * @return StorageClient
     */
    private function getStorageClient(): StorageClient
    {
        if ($this->storageClient === null) {
            $this->storageClient = new StorageClient([
                // Suppress notice when using gcloud ADC (lacks project ID in keyfile)
                // Project ID not needed for bucket reads by name
                'suppressKeyFileNotice' => true,
            ]);
        }

        return $this->storageClient;
    }

    /**
     * Resolve a file path to an absolute path.
     *
     * @param string $filePath The file path (can be absolute or relative)
     * @return string Absolute file path
     */
    public function resolveFilePath(string $filePath): string
    {
        // If it's already an absolute path, return as-is
        if (str_starts_with($filePath, '/')) {
            return $filePath;
        }

        // If it's a URL (http/https), return as-is
        if (preg_match('/^https?:\/\//', $filePath)) {
            return $filePath;
        }

        // Otherwise, treat as relative to Laravel's base path
        return base_path($filePath);
    }

}
