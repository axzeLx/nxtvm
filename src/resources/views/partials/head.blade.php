<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<script>
    // Apply palette and light / dark mode before first paint
    (function () {
        var r = document.documentElement, t = r.dataset.theme, m = r.dataset.mode;
        try {
            if (!r.dataset.appearanceUrl) { t = localStorage.getItem('theme') || t; m = localStorage.getItem('mode') || m; }
            else { localStorage.setItem('theme', t); localStorage.setItem('mode', m); }
        } catch (e) {}
        r.dataset.theme = t || 'teal';
        r.dataset.mode = m || 'system';
        if (m === 'dark' || ((m || 'system') === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            r.classList.add('dark');
        }
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
