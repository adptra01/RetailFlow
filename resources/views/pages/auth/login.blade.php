<x-layouts::auth title="Masuk">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Masuk ke akun Anda" description="Masukkan email dan kata sandi Anda di bawah untuk masuk" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input name="email" label="Alamat Email" :value="old('email')" type="email" required autofocus
                autocomplete="email" placeholder="email@contoh.com" />

            <div class="relative">
                <flux:input name="password" label="Kata Sandi" type="password" required
                    autocomplete="current-password" placeholder="Kata Sandi" viewable />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')">
                        Lupa kata sandi?
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" label="Ingat saya" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    Masuk
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>Belum punya akun?</span>
                <flux:link :href="route('register')">Daftar</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>