<?php

use App\Models\Transaction;
use App\Models\Setting;

use function Livewire\Volt\mount;
use function Livewire\Volt\state;

state([
    'transaction' => null,
    'setting' => null,
]);

mount(function (Transaction $transaction) {
    $this->transaction = $transaction->load('items.product');
    $this->setting = Setting::first();
});

?>

<html>

<head>
    <title>Struk #{{ $transaction->invoice_number }}</title>
    <style>
        /* =========================================
       1. PENGATURAN TAMPILAN DI LAYAR (SCREEN)
       ========================================= */
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            line-height: 1.4;
            width: 80mm;
            /* Lebar simulasi di layar komputer */
            margin: 0 auto;
            padding: 10px;
            color: #000;
            background-color: #fff;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }

        .header {
            margin-bottom: 10px;
        }

        .store-name {
            font-size: 16px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            border-bottom: 1px dashed #000;
            padding: 5px 0;
        }

        td {
            padding: 3px 0;
            vertical-align: top;
        }

        .total-section td {
            padding-top: 5px;
        }

        .footer {
            margin-top: 15px;
            font-size: 10px;
        }

        /* =========================================
       2. ATURAN KETAT UNTUK MESIN PRINTER (PRINT)
       ========================================= */
        @media print {

            /* Aturan spesifik untuk dimensi dan margin kertas */
            @page {
                /* 
              Ukuran Kertas: 80mm adalah standar Thermal Printer besar. 
              Ganti ke 58mm jika Anda menggunakan printer bluetooth/kecil.
              'auto' berarti panjang kertas tidak dibatasi (terus menggulung sesuai isi teks).
            */
                size: 80mm auto;

                /* 
              Margin 0 sangat krusial! Ini akan memaksa browser (Chrome/Firefox) 
              untuk TIDAK mencetak tulisan URL website di atas dan Tanggal/Halaman di bawah kertas.
            */
                margin: 0;
            }

            /* Aturan area konten saat dicetak */
            body {
                width: 80mm;
                /* Lebar area cetak mentok kanan-kiri */
                margin: 0;
                padding: 2mm;
                /* Beri jarak aman 2mm agar huruf tidak terpotong tepi kertas */

                /* Memaksa warna tetap hitam pekat (penting untuk printer thermal monokrom) */
                color: #000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;

                /* Menghilangkan background warna jika ada */
                background-color: transparent !important;
            }

            /* Menyembunyikan elemen UI (tombol print, dll) agar tidak ikut tercetak */
            .no-print {
                display: none !important;
            }

            /* Memastikan baris item tidak terbelah di tengah jika terjadi pergantian halaman (jarang di roll paper, tapi best practice) */
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            /* Menghilangkan bayangan atau efek yang bisa membuat printer thermal nge-blur */
            * {
                box-shadow: none !important;
                text-shadow: none !important;
            }
        }
    </style>
</head>

<body>
    <div class="no-print"
        style="background: #f4f4f4; padding: 10px; margin-bottom: 20px; text-align: center; border-radius: 8px;">
        <button onclick="window.print()"
            style="padding: 8px 16px; cursor: pointer; background: #18181b; color: white; border: none; border-radius: 4px; font-weight: bold;">
            Cetak
        </button>
        <a href="{{ route('transactions.index') }}"
            style="padding: 8px 16px; cursor: pointer; background: #e4e4e7; color: #18181b; border: none; border-radius: 4px; margin-left: 10px;">
            Tutup
    </a>
    </div>

    @volt
    <div class="receipt">
        <div class="header text-center">
            <div class="store-name">{{ $setting->store_name }}</div>
            <div>{{ $setting->store_address }}</div>
            <div>Telp: {{ $setting->store_phone }}</div>
        </div>

        <div class="divider"></div>

        <div>
            <div>No: {{ $transaction->invoice_number }}</div>
            <div>Tgl: {{ $transaction->created_at->format('d/m/Y H:i') }}</div>
            <div>Kasir: {{ $transaction->user?->name ?? __('System') }}</div>
            @if($transaction->customer_name && $transaction->customer_name !== 'Umum')
                <div>Plgn: {{ $transaction->customer_name }}</div>
            @endif
        </div>

        <div class="divider"></div>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th class="text-right">Jml</th>
                    <th class="text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaction->items as $item)
                    <tr>
                        <td>
                            {{ $item->product->name }}<br>
                            <small>{{ Number::currency($item->unit_price, 'IDR', 'id') }}</small>
                        </td>
                        <td class="text-right">{{ $item->quantity }}</td>
                        <td class="text-right">{{ Number::currency($item->unit_price * $item->quantity ?? 0, 'IDR', 'id') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <table class="total-section">
            <tr>
                <td class="bold">{{ __('Total') }}</td>
                <td class="text-right bold">{{ Number::currency($transaction->total_amount, 'IDR', 'id') }}</td>
            </tr>
            <tr>
                <td>Bayar ({{ strtoupper($transaction->payment_method) }})</td>
                <td class="text-right">{{ Number::currency($transaction->paid_amount, 'IDR', 'id') }}</td>
            </tr>
            <tr>
                <td class="bold">Kembali</td>
                <td class="text-right bold">{{ Number::currency($transaction->change_amount, 'IDR', 'id') }}</td>
            </tr>
        </table>

        <div class="divider"></div>

        <div class="footer text-center">
            <p>{{ $setting->receipt_footer }}</p>
            <p>Terima Kasih</p>
        </div>
    </div>
    @endvolt

    <script>
        // Auto print ketika halaman selesai dimuat
        window.onload = function () {
            window.print();
        };

        // Auto close: Otomatis menutup tab setelah jendela print hilang (berhasil cetak atau batal)
        // window.onafterprint = function () {
        //     window.close();
        // };
    </script>
</body>

</html>