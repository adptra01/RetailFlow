import { openBarcodeScanner, closeBarcodeScanner } from './barcode-scanner';
import { recognizeProductFromImage } from './ocr-engine';

// API global untuk halaman POS (Alpine): scan barcode lewat kamera.
window.posBarcodeScanner = {
    open: openBarcodeScanner,
    close: closeBarcodeScanner,
};

// API global untuk Bantuan Input Produk (OCR)
window.posOcrEngine = {
    recognize: recognizeProductFromImage,
};

