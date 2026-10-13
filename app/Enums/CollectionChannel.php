<?php

namespace App\Enums;

/**
 * Saluran penagihan piutang (FR-07 "Catatan penagihan").
 *
 * Nilai disimpan apa adanya di `receivable_notes.channel`; pemakaian lewat enum
 * agar UI dan validasi memakai daftar yang sama.
 */
enum CollectionChannel: string
{
    case Phone = 'phone';

    case Whatsapp = 'whatsapp';

    case Sms = 'sms';

    case Visit = 'visit';

    case Letter = 'letter';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Telepon',
            self::Whatsapp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Visit => 'Kunjungan',
            self::Letter => 'Surat',
            self::Other => 'Lainnya',
        };
    }
}
