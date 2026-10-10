# RetailFlow (laravel13-pos)

POS Universal — rebuild bertahap sesuai `.ai/plan/PRD, TDD, SAD.md`.

## Dokumentasi

- **Prosedur dukungan / runbook** — [`docs/SUPPORT.md`](docs/SUPPORT.md)
  (diagnostik §23, pemulihan & restore, izin FR-12, alert FR-10).
- **Riwayat perubahan** — [`CHANGELOG.md`](CHANGELOG.md).

## Perintah operasional

```bash
php artisan pos:backup:status       # status backup terakhir (FR-11)
php artisan pos:backup:create       # backup manual
php artisan pos:backup:restore <f>  # pulihkan (validasi + konfirmasi)
php artisan pos:alerts:generate     # alert lokal (FR-10)
php artisan pos:roles:seed          # seed peran & izin awal (FR-12)
```

## Pengujian

```bash
php artisan test
```
