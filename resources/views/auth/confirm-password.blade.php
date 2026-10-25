@extends('auth.layout')

@section('title', 'Konfirmasi kata sandi')
@section('heading', 'Konfirmasi kata sandi')

@section('content')
    @include('auth.form-errors')
    <p>Untuk keamanan, masukkan kembali kata sandi Anda sebelum melanjutkan.</p>
    <form method="POST" action="/user/confirm-password">
        @csrf
        <label for="password">Kata sandi</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button type="submit">Konfirmasi</button>
    </form>
@endsection
