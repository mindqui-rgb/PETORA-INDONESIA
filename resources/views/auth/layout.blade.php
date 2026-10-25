<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #17352b; background: #f4f7f3; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.25rem; box-sizing: border-box; }
        main { width: min(100%, 27rem); background: white; padding: 2rem; border-radius: 1rem; box-shadow: 0 12px 40px #15392a16; }
        h1 { margin: 0 0 .5rem; font-size: 1.55rem; } p { color: #52645c; line-height: 1.55; }
        label { display: block; font-weight: 600; margin: 1rem 0 .4rem; }
        input { box-sizing: border-box; width: 100%; padding: .75rem; border: 1px solid #cbd5ce; border-radius: .5rem; font: inherit; }
        button { width: 100%; margin-top: 1.25rem; padding: .8rem; border: 0; border-radius: .5rem; background: #16734c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        a { color: #126544; } .links { display: flex; justify-content: space-between; gap: .75rem; margin-top: 1rem; font-size: .95rem; }
        .notice { padding: .75rem; border-radius: .5rem; background: #edf8f0; color: #185b38; }
        .errors { padding: .75rem 1rem; border-radius: .5rem; background: #fff0ed; color: #8a2d1c; }
        .errors ul { margin: 0; padding-left: 1.25rem; }
    </style>
</head>
<body>
<main>
    <a href="/" aria-label="Beranda PETORA">{{ config('app.name') }}</a>
    <h1>@yield('heading')</h1>
    @yield('content')
</main>
</body>
</html>
