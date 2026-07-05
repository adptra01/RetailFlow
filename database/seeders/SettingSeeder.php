<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::create([
            'store_name' => 'Laravel POS',
            'store_address' => 'Jl. Jendral Sudirman No. 123, Jakarta Selatan',
            'store_phone' => '021-1234567',
            'store_email' => 'contact@laravelpos.com',
            'receipt_footer' => 'Terima kasih telah berbelanja di toko kami!',
        ]);
    }
}
