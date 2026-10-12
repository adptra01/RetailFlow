/**
 * Mesin OCR Offline-First menggunakan Tesseract.js (FR-03 §20).
 * Berjalan sepenuhnya di sisi klien (browser/WebWorker) tanpa mengirim foto
 * ke server eksternal, menjaga privasi data produk/toko.
 */

import { createWorker } from 'tesseract.js';

let worker = null;

async function getWorker() {
    if (!worker) {
        // Inisialisasi Tesseract worker dengan bahasa Inggris/Indonesia.
        worker = await createWorker('eng+ind');
    }
    return worker;
}

/**
 * Ekstrak teks dari file gambar atau URL, lalu parse menjadi kandidat field produk.
 * 
 * @param {File|string} imageSource
 * @returns {Promise<{rawText: string, name?: string, price?: number, barcode?: string}>}
 */
export async function recognizeProductFromImage(imageSource) {
    const w = await getWorker();
    const ret = await w.recognize(imageSource);
    const text = ret.data.text || '';

    // Parsing dasar kandidat dari teks OCR
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    
    let candidateName = '';
    let candidatePrice = null;
    let candidateBarcode = '';

    for (const line of lines) {
        // Cari angka yang mirip harga (misal ada titik/koma atau nominal besar)
        const cleanNum = line.replace(/[^0-9]/g, '');
        if (!candidatePrice && cleanNum.length >= 3 && cleanNum.length <= 8) {
            const num = parseInt(cleanNum, 10);
            if (num >= 500 && num <= 50000000) {
                candidatePrice = num;
            }
        }

        // Cari kode batang (barcode) standar 8-13 digit angka
        if (!candidateBarcode && /^\d{8,13}$/.test(line)) {
            candidateBarcode = line;
        }

        // Ambil baris pertama yang alfabetis panjang sebagai nama produk
        if (!candidateName && line.length >= 3 && /[a-zA-Z]/.test(line) && !/^\d+$/.test(line)) {
            candidateName = line;
        }
    }

    return {
        rawText: text,
        name: candidateName,
        price: candidatePrice,
        barcode: candidateBarcode,
    };
}
