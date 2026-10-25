<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akun pelanggan · {{ config('app.name') }}</title>
</head>
<body>
    <header>
        <a href="/">{{ config('app.name') }}</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Keluar</button>
        </form>
    </header>
    <main>
        <h1>Hewan saya</h1>
        <p>Hewan yang terhubung dengan akun Anda.</p>
        @forelse ($pets as $pet)
            <article>
                <h2>{{ $pet->name }}</h2>
                <p>{{ $pet->species }}@if ($pet->breed) · {{ $pet->breed }}@endif</p>
                <p>{{ $pet->is_primary ? 'Wali utama' : 'Wali sekunder' }}</p>
            </article>
        @empty
            <p>Belum ada hewan yang tertaut. Hubungi staf cabang untuk menautkan data hewan Anda.</p>
        @endforelse
    </main>
</body>
</html>
