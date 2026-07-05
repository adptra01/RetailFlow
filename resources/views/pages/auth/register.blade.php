<x-layouts::auth title="Daftar">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Buat Akun" description="Masukkan detail Anda di bawah untuk membuat akun" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <flux:input name="name" label="Nama" :value="old('name')" type="text" required autofocus
                autocomplete="name" placeholder="Nama lengkap" />

            <flux:input name="email" label="Alamat Email" :value="old('email')" type="email" required
                autocomplete="email" placeholder="email@contoh.com" />

            <flux:input name="password" label="Kata Sandi" type="password" required autocomplete="new-password"
                placeholder="Kata Sandi" viewable />

            <flux:input name="password_confirmation" label="Konfirmasi Kata Sandi" type="password" required
                autocomplete="new-password" placeholder="Konfirmasi Kata Sandi" viewable />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    Buat Akun
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>Sudah punya akun?</span>
            <flux:link :href="route('login')">Masuk</flux:link>
        </div>
    </div>
</x-layouts::auth>