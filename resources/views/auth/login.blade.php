@extends('auth.layout')

@section('title', 'Masuk')
@section('heading', 'Masuk ke akun')

@section('content')
    @include('auth.form-errors')
    <form method="POST" action="{{ route('masuk.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Kata sandi</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label><input name="remember" type="checkbox" value="1" style="width:auto"> Ingat saya</label>
        <button type="submit">Masuk</button>
    </form>
    <div class="links">
        <a href="{{ route('lupa-kata-sandi') }}">Lupa kata sandi?</a>
        <a href="{{ route('daftar') }}">Buat akun</a>
    </div>
@endsection
