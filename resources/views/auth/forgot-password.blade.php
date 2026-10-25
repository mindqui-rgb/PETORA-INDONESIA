@extends('auth.layout')

@section('title', 'Lupa kata sandi')
@section('heading', 'Atur ulang kata sandi')

@section('content')
    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif
    @include('auth.form-errors')
    <p>Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat kata sandi baru.</p>
    <form method="POST" action="{{ route('lupa-kata-sandi.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        <button type="submit">Kirim tautan</button>
    </form>
    <div class="links"><a href="{{ route('masuk') }}">Kembali ke masuk</a></div>
@endsection
