@extends('auth.layout')

@section('title', 'Verifikasi email')
@section('heading', 'Verifikasi email Anda')

@section('content')
    @if (session('status') === 'verification-link-sent')
        <p class="notice">Tautan verifikasi baru telah dikirim ke email Anda.</p>
    @endif
    <p>Kami telah mengirim tautan verifikasi ke alamat email Anda. Verifikasi email sebelum menggunakan portal pelanggan.</p>
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit">Kirim ulang tautan verifikasi</button>
    </form>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" style="background:#52645c">Keluar</button>
    </form>
@endsection
