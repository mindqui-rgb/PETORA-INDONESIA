@extends('auth.layout')

@section('title', 'Kata sandi baru')
@section('heading', 'Buat kata sandi baru')

@section('content')
    @include('auth.form-errors')
    <form method="POST" action="{{ route('reset-kata-sandi.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" autocomplete="email" required>
        <label for="password">Kata sandi (minimal 10 karakter)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
        <label for="password_confirmation">Ulangi kata sandi</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button type="submit">Simpan kata sandi</button>
    </form>
@endsection
