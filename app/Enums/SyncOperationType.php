<?php

namespace App\Enums;

/**
 * Jenis operasi sinkronisasi (§11.2). Dipisah dari `entity_type` sehingga
 * satu entitas dapat memiliki beberapa operasi (create/update/delete).
 */
enum SyncOperationType: string
{
    case Create = 'create';

    case Update = 'update';

    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Buat',
            self::Update => 'Ubah',
            self::Delete => 'Hapus',
        };
    }
}
