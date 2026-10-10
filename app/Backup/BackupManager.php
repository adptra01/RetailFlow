<?php

namespace App\Backup;

use App\Enums\Permission;
use App\Exceptions\Backup\BackupCorrupted;
use App\Exceptions\Backup\BackupException;
use App\Exceptions\Backup\BackupFailed;
use App\Exceptions\Backup\BackupNotFound;
use App\Exceptions\Backup\BackupRequiresConfirmation;
use App\Exceptions\Backup\BackupSchemaMismatch;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Use case FR-11 (Backup dan pemulihan): `CreateBackup` dan `RestoreBackup`.
 *
 * - Backup manual: snapshot konsisten via driver, plus sidecar metadata
 *   (checksum + versi skema) dan manifest (waktu backup terakhir, §23).
 * - Restore WAJIB memvalidasi integritas & versi skema, menolak tanpa
 *   konfirmasi eksplisit, dan membuat snapshot keselamatan database aktif
 *   SECARA OTOMATIS sebelum menimpa (FR-11 + plan §21).
 * - Ekspor data (CSV dsb.) bukan pengganti backup database yang konsisten.
 * - Enkripsi opsional (AES-256-CBC via APP_KEY) untuk data sensitif.
 */
final class BackupManager
{
    public function __construct(
        private readonly BackupDriver $driver,
        private readonly string $backupDir,
        private readonly string $schemaVersion,
        private readonly bool $encrypt = false,
    ) {}

    public static function forDefault(): self
    {
        return new self(
            BackupDriverFactory::forConnection(),
            (string) config('pos.backup.path', storage_path('app/backups')),
            (string) config('pos.schema_version', '0.4.0'),
            (bool) config('pos.backup.encrypt', false),
        );
    }

    /**
     * Buat backup manual (FR-11).
     *
     * @param  string|null  $directory  lokasi tujuan (media eksternal / pilihan pengguna)
     * @param  bool|null  $encrypt  timpa konfigurasi default per panggilan
     */
    public function create(?string $directory = null, ?bool $encrypt = null): BackupInfo
    {
        $directory ??= $this->backupDir;
        $encrypt ??= $this->encrypt;

        $this->ensureDirectory($directory);
        $this->ensureDirectory($this->backupDir);

        $basename = 'pos-backup-'.now()->format('Ymd-His').'-'.Str::uuid7().'.dump';
        $temporary = $this->backupDir.'/.tmp-'.$basename;

        try {
            $this->driver->createSnapshot($temporary);
        } catch (BackupException $e) {
            @unlink($temporary);

            throw $e;
        }

        if ($encrypt) {
            try {
                $stored = Crypt::encryptString((string) file_get_contents($temporary));
            } catch (\Throwable $e) {
                @unlink($temporary);

                throw new BackupFailed('Enkripsi backup gagal: '.$e->getMessage(), 0, $e);
            }

            $target = $directory.'/'.$basename.'.enc';
            $this->writeAtomic($target, $stored);
            @unlink($temporary);
        } else {
            $target = $directory.'/'.$basename;

            if (! copy($temporary, $target)) {
                @unlink($temporary);

                throw new BackupFailed('Tidak dapat menulis backup ke '.$target);
            }

            @unlink($temporary);
        }

        $info = BackupInfo::fromFile(
            $target,
            $this->schemaVersion,
            (string) config('pos.version', '0.4.0'),
            $encrypt,
            $this->driver->name(),
        );

        $this->writeAtomic($this->sidecarPath($target), $this->encode($info->toArray()));
        $this->register($info);

        return $info;
    }

    /**
     * Pulihkan backup ke database (FR-11 + plan §21).
     *
     * Bila aktor diberikan, otorisasi FR-12 diperiksa: hanya yang memiliki
     * izin `backup.restore` yang boleh restore. Tanpa aktor (CLI operator),
     * lapisan pemanggil yang bertanggung jawab atas otorisasi.
     *
     * Alur: cek file → validasi integritas (checksum) → validasi versi skema →
     * konfirmasi eksplisit → snapshot keselamatan otomatis → restore →
     * pemeriksaan kesehatan pasca-restore.
     *
     * @return BackupInfo snapshot keselamatan pra-restore (dibuat otomatis)
     */
    public function restore(string $backupFile, bool $force = false, ?User $actor = null): BackupInfo
    {
        ActionAuthorizer::assertIf($actor, Permission::RestoreBackup);

        $path = str_starts_with($backupFile, '/')
            ? $backupFile
            : rtrim($this->backupDir, '/').'/'.$backupFile;

        if (! is_file($path)) {
            throw new BackupNotFound("File backup tidak ditemukan: {$backupFile}");
        }

        $info = $this->readSidecar($path);
        $this->assertIntegrity($path, $info, $backupFile);

        if (version_compare($info->schemaVersion, $this->schemaVersion, '>')) {
            throw new BackupSchemaMismatch(
                "Backup dibuat dengan skema {$info->schemaVersion}, lebih baru dari skema aplikasi saat ini ({$this->schemaVersion})."
            );
        }

        if (! $force) {
            throw new BackupRequiresConfirmation(
                'Restore menimpa database aktif. Konfirmasi dengan --force; snapshot keselamatan database saat ini dibuat otomatis sebelum restore.'
            );
        }

        // Prosedur keselamatan §21: snapshot database aktif SEBELUM menimpa.
        $safety = $this->create($this->backupDir.'/pre-restore');

        $source = $path;
        $temporary = null;

        if ($info->encrypted) {
            $temporary = $this->decryptToTemporary($path, $info);
            $source = $temporary;
        }

        try {
            $this->driver->restoreSnapshot($source);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        if (! $this->driver->isHealthy()) {
            throw new BackupFailed('Database hasil restore gagal pemeriksaan kesehatan.');
        }

        return $safety;
    }

    /**
     * Waktu & info backup terakhir (FR-11: "Sistem menampilkan waktu backup
     * terakhir"; observability §23).
     */
    public function latest(): ?BackupInfo
    {
        $manifest = $this->readManifest();

        return isset($manifest['backups'][0])
            ? BackupInfo::fromArray($manifest['backups'][0])
            : null;
    }

    /**
     * @return list<BackupInfo> terbaru dulu
     */
    public function all(): array
    {
        return array_map(
            fn (array $entry) => BackupInfo::fromArray($entry),
            $this->readManifest()['backups'],
        );
    }

    public function manifestPath(): string
    {
        return $this->backupDir.'/manifest.json';
    }

    /**
     * @return array{last_backup_at: string|null, backups: list<array<string, mixed>>}
     */
    private function readManifest(): array
    {
        $default = ['last_backup_at' => null, 'backups' => []];

        if (! is_file($this->manifestPath())) {
            return $default;
        }

        $data = json_decode((string) file_get_contents($this->manifestPath()), true);

        if (! is_array($data)) {
            return $default;
        }

        return $data + $default;
    }

    private function register(BackupInfo $info): void
    {
        $manifest = $this->readManifest();
        array_unshift($manifest['backups'], $info->toArray());
        $manifest['last_backup_at'] = $info->createdAt;

        $this->writeAtomic($this->manifestPath(), $this->encode($manifest));
    }

    private function readSidecar(string $path): BackupInfo
    {
        $metadataPath = $this->sidecarPath($path);

        if (! is_file($metadataPath)) {
            throw new BackupCorrupted(
                "Metadata backup tidak ditemukan ({$metadataPath}). Restore wajib memvalidasi versi skema dan integritas file (FR-11); ekspor data bukan pengganti backup database yang konsisten."
            );
        }

        $data = json_decode((string) file_get_contents($metadataPath), true);

        if (! is_array($data)
            || ! isset($data['checksum'], $data['schema_version'], $data['encrypted'], $data['created_at'])) {
            throw new BackupCorrupted('Metadata backup tidak lengkap atau rusak — restore ditolak.');
        }

        return BackupInfo::fromArray($data);
    }

    private function assertIntegrity(string $path, BackupInfo $info, string $requested): void
    {
        if (! $info->checksumMatches($path)) {
            throw new BackupCorrupted(
                "Checksum backup tidak cocok — file '{$requested}' rusak atau diubah."
            );
        }
    }

    private function decryptToTemporary(string $path, BackupInfo $info): string
    {
        try {
            $contents = Crypt::decryptString((string) file_get_contents($path));
        } catch (DecryptException) {
            throw new BackupCorrupted(
                'Backup terenkripsi tidak dapat didekripsi (kunci aplikasi berbeda atau file rusak).'
            );
        }

        $temporary = $this->backupDir.'/.restore-'.$info->filename.'.tmp';
        $this->writeAtomic($temporary, $contents);

        return $temporary;
    }

    private function sidecarPath(string $path): string
    {
        $path = str_ends_with($path, '.enc') ? substr($path, 0, -4) : $path;

        return $path.'.meta.json';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            @unlink($temporary);

            throw new BackupFailed('Gagal menulis file: '.$path);
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new BackupFailed('Gagal memfinalisasi file: '.$path);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
