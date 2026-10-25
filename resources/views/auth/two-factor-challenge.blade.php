@extends('auth.layout')

@section('title', 'Verifikasi MFA')
@section('heading', 'Verifikasi identitas')

@section('content')
    @include('auth.form-errors')
    <p>Masukkan kode autentikasi atau salah satu kode pemulihan.</p>
    <form method="POST" action="/two-factor-challenge">
        @csrf
        <label for="code">Kode autentikasi</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code">
        <label for="recovery_code">Kode pemulihan (jika diperlukan)</label>
        <input id="recovery_code" name="recovery_code" autocomplete="one-time-code">
        <button type="submit">Verifikasi</button>
    </form>
@endsection
