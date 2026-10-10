<?php

namespace App\Support\Sync;

use App\Models\SyncInbox;

/**
 * Penerjemah satu operasi sinkronisasi menjadi tindakan domain (§11.3
 * langkah 6). Implementasi WAJIB idempoten: menerapkan operasi yang sama dua
 * kali tidak boleh menggandakan efek domain (§11.1).
 *
 * Idempotensi dijaga berlapis:
 * - `sync_inbox.operation_id` unik mencegah penerimaan ganda;
 * - status `received` → `applied` membuat penerapan berikutnya dilewati;
 * - aksi domain memakai `operation_id` sebagai kunci idempotensi.
 */
interface SyncOperationHandler
{
    /**
     * Apakah handler ini menangani operasi tersebut.
     */
    public function supports(SyncInbox $operation): bool;

    /**
     * Terapkan operasi ke domain. Tidak boleh melempar untuk kegagalan yang
     * dapat dilaporkan; kembalikan hasilnya sebagai `SyncApplicationResult`.
     */
    public function apply(SyncInbox $operation): SyncApplicationResult;
}
