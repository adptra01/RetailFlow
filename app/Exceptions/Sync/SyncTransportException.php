<?php

namespace App\Exceptions\Sync;

use RuntimeException;

/**
 * Kegagalan transport saat perangkat mengirim batch sinkronisasi (§11.3).
 *
 * Berbeda dari penolakan domain server: hasil pengiriman TIDAK DIKETAHUI,
 * sehingga operasi tetap dapat diulang dan tidak boleh dianggap gagal
 * permanen ("Perangkat tidak boleh menganggap operasi gagal hanya karena
 * respons server tidak diterima").
 */
class SyncTransportException extends RuntimeException {}
