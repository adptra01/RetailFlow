<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['name' => 'Ahmad Fauzi', 'phone' => '081234567890', 'credit_limit' => 500000],
            ['name' => 'Siti Nurhaliza', 'phone' => '082345678901', 'credit_limit' => 1000000],
            ['name' => 'Bambang Wijaya', 'phone' => '083456789012', 'credit_limit' => 250000],
            ['name' => 'Dewi Lestari', 'phone' => '084567890123', 'credit_limit' => 750000],
            ['name' => 'Rudi Hartono', 'phone' => '085678901234', 'credit_limit' => 300000],
            ['name' => 'Mega Putri', 'phone' => '086789012345', 'credit_limit' => 0],
            ['name' => 'Agus Salim', 'phone' => '087890123456', 'credit_limit' => 1000000],
            ['name' => 'Rina Marlina', 'phone' => '088901234567', 'credit_limit' => 0],
            ['name' => 'Toko Berkah', 'phone' => '089012345678', 'credit_limit' => 2000000],
            ['name' => 'Warung Sambal', 'phone' => '080123456789', 'credit_limit' => 500000],
        ];

        foreach ($customers as $customer) {
            Customer::create($customer);
        }
    }
}
