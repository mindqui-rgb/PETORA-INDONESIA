@extends('auth.layout')

@section('title', 'Daftar')
@section('heading', 'Buat akun pelanggan')

@section('content')
    @include('auth.form-errors')
    <form method="POST" action="{{ route('daftar.store') }}">
        @csrf
        <label for="name">Nama lengkap</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" required autofocus>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        <label for="password">Kata sandi (minimal 10 karakter)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>
        <label for="password_confirmation">Ulangi kata sandi</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button type="submit">Daftar</button>
    </form>
    <div class="links"><a href="{{ route('masuk') }}">Sudah punya akun? Masuk</a></div>
@endsection
