<?php

namespace App\Backup;

/**
 * Metadata satu file backup (sidecar `*.meta.json` + entri manifest).
 *
 * Menyimpan apa yang dibutuhkan validasi FR-11: checksum integritas, versi
 * skema, flag enkripsi, dan identitas pembuat.
 */
final readonly class BackupInfo
{
    public function __construct(
        public string $filename,
        public string $path,
        public int $size,
        public string $checksum,
        public string $schemaVersion,
        public string $appVersion,
        public string $driver,
        public bool $encrypted,
        public ?string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'path' => $this->path,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'schema_version' => $this->schemaVersion,
            'app_version' => $this->appVersion,
            'driver' => $this->driver,
            'encrypted' => $this->encrypted,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            filename: (string) ($data['filename'] ?? basename((string) ($data['path'] ?? ''))),
            path: (string) ($data['path'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            checksum: (string) ($data['checksum'] ?? ''),
            schemaVersion: (string) ($data['schema_version'] ?? ''),
            appVersion: (string) ($data['app_version'] ?? ''),
            driver: (string) ($data['driver'] ?? ''),
            encrypted: (bool) ($data['encrypted'] ?? false),
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
        );
    }

    public static function fromFile(
        string $path,
        string $schemaVersion,
        string $appVersion,
        bool $encrypted,
        string $driver,
    ): self {
        return new self(
            filename: basename($path),
            path: $path,
            size: (int) filesize($path),
            checksum: self::checksumOf($path),
            schemaVersion: $schemaVersion,
            appVersion: $appVersion,
            driver: $driver,
            encrypted: $encrypted,
            createdAt: now()->toIso8601String(),
        );
    }

    public static function checksumOf(string $path): string
    {
        return 'sha256:'.hash_file('sha256', $path);
    }

    public function checksumMatches(string $path): bool
    {
        return self::checksumOf($path) === $this->checksum;
    }
}
