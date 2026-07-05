<x-layouts::auth title="Konfirmasi Kata Sandi">
    <div class="flex flex-col gap-6">
        <x-auth-header
            title="Konfirmasi Kata Sandi"
            description="Ini adalah area aman aplikasi. Harap konfirmasi kata sandi Anda sebelum melanjutkan."
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                label="Kata Sandi"
                type="password"
                required
                autocomplete="current-password"
                placeholder="Kata Sandi"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                Konfirmasi
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
