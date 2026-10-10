<?php

namespace App\Exceptions\Printing;

use RuntimeException;

/**
 * Kegagalan pencetakan struk. Bersifat operasional: TIDAK boleh membatalkan
 * penjualan yang sudah committed (plan §17.3, §20).
 */
class PrinterException extends RuntimeException {}
