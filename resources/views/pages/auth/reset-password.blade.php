<x-layouts::auth title="Reset Kata Sandi">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Reset Kata Sandi" description="Silakan masukkan kata sandi baru Anda di bawah" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <flux:input
                name="email"
                value="{{ request('email') }}"
                label="Email"
                type="email"
                required
                autocomplete="email"
            />

            <flux:input
                name="password"
                label="Kata Sandi"
                type="password"
                required
                autocomplete="new-password"
                placeholder="Kata Sandi"
                viewable
            />

            <flux:input
                name="password_confirmation"
                label="Konfirmasi Kata Sandi"
                type="password"
                required
                autocomplete="new-password"
                placeholder="Konfirmasi Kata Sandi"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    Reset Kata Sandi
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
