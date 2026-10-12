<?php

use App\Support\Imports\ProductCsvParser;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.auth.login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Template CSV impor produk (FR-02): berkas contoh yang bisa diisi pengguna.
    Route::get('imports/products/template', function () {
        return response(ProductCsvParser::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-impor-produk.csv"',
        ]);
    })->name('imports.products.template');
});

require __DIR__.'/settings.php';
