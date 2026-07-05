<x-layouts::auth title="Verifikasi Email">
    <div class="mt-4 flex flex-col gap-6">
        <flux:text class="text-center">
            Harap verifikasi alamat email Anda dengan mengklik tautan yang baru saja kami kirimkan ke email Anda.
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !dark:text-green-400 !text-green-600">
                Tautan verifikasi baru telah dikirim ke alamat email yang Anda berikan saat pendaftaran.
            </flux:text>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    Kirim Ulang Email Verifikasi
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button">
                    Keluar
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
