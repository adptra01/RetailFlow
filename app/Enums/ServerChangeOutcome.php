<?php

namespace App\Enums;

/**
 * Hasil penerapan satu perubahan server pada basis data lokal perangkat
 * (§11.3 langkah 10).
 *
 * - `Applied`    — perubahan diterapkan (dibuat/diubah/dihapus);
 * - `Skipped`    — tidak ada yang perlu dilakukan: perubahan milik perangkat
 *                  ini sendiri (§11.1), jenis entitas tidak dicerminkan
 *                  perangkat, atau data lokal sudah sama (§11.1
 *                  "Sinkronisasi harus aman terhadap pengiriman ulang");
 * - `Conflicted` — data lokal lebih baru daripada perubahan server sehingga
 *                  tidak ditimpa; kasus konflik dicatat (§11.3 langkah 11).
 */
enum ServerChangeOutcome: string
{
    case Applied = 'applied';

    case Skipped = 'skipped';

    case Conflicted = 'conflicted';
}
