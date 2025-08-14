<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Welcome • Laravel</title>

    <!-- Prefers-color-scheme + Theme init -->
    <script>
        // set theme before paint
        (function() {
            const ls = localStorage.getItem('theme');
            const mq = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            const theme = ls || mq;
            if (theme === 'dark') document.documentElement.classList.add('dark');
            else document.documentElement.classList.remove('dark');
        })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    <!-- Styles / Scripts -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            /* Tailwind minimal preset (از همونfallback خودت الهام گرفته) */
            :root {
                --bg-dark: #05050a;
                --bg-darker: #030308;
                --card: #0e0f14;
                --muted: #a1a1aa;
                --ring: #ffffff22;
                --grad-a: #7c3aed;
                --grad-b: #22d3ee;
                --glass: #0b0c12b3;
            }

            * {
                box-sizing: border-box
            }

            html,
            body {
                height: 100%
            }

            body {
                margin: 0;
                font-family: Inter, ui-sans-serif, system-ui, Segoe UI, Roboto, Helvetica, Arial;
                line-height: 1.6;
                background: radial-gradient(1200px 800px at 10% 10%, #0f172a 0%, transparent 60%),
                    radial-gradient(1200px 800px at 90% 90%, #111827 0%, transparent 60%),
                    var(--bg-dark);
                color: #e5e7eb;
                overflow-x: hidden
            }

            .gridfx::before {
                content: "";
                position: absolute;
                inset: 0;
                background:
                    linear-gradient(transparent 0 97%, #ffffff10 97% 100%) 0 0/ 100% 24px,
                    linear-gradient(90deg, transparent 0 97%, #ffffff10 97% 100%) 0 0/ 24px 100%;
                mask: radial-gradient(ellipse at center, black 40%, transparent 70%);
                pointer-events: none
            }

            .blob {
                position: absolute;
                width: 60vmax;
                height: 60vmax;
                filter: blur(60px);
                opacity: .25;
                z-index: 0;
                background: conic-gradient(from 0deg, var(--grad-a), var(--grad-b), var(--grad-a));
                animation: spin 24s linear infinite;
                top: -15vmax;
                right: -10vmax;
                border-radius: 50%;
            }

            @keyframes spin {
                to {
                    transform: rotate(360deg)
                }
            }

            .container {
                max-width: 1100px;
                margin: 0 auto;
                padding: 24px
            }

            .glass {
                background: var(--glass);
                border: 1px solid var(--ring);
                box-shadow: 0 10px 40px #00000066, inset 0 1px #ffffff0a;
                backdrop-filter: blur(12px);
                border-radius: 20px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: .6rem;
                padding: .7rem 1rem;
                border-radius: 12px;
                border: 1px solid #ffffff20;
                text-decoration: none;
                color: white;
                transition: .2s
            }

            .btn:hover {
                border-color: #ffffff40;
                transform: translateY(-1px)
            }

            .btn.primary {
                background: linear-gradient(135deg, var(--grad-a), var(--grad-b))
            }

            .btn.ghost {
                background: transparent
            }

            .muted {
                color: var(--muted)
            }

            .cards {
                display: grid;
                grid-template-columns: repeat(12, 1fr);
                gap: 16px
            }

            .card {
                grid-column: span 12;
                background: linear-gradient(180deg, #0b0c12aa, #0b0c1200), var(--card);
                border: 1px solid var(--ring);
                border-radius: 16px;
                padding: 18px
            }

            @media (min-width: 768px) {
                .card {
                    grid-column: span 4
                }
            }

            .chip {
                display: inline-flex;
                gap: .5rem;
                align-items: center;
                font-size: .8rem;
                color: #a3a3a3;
                border: 1px solid #ffffff1a;
                border-radius: 999px;
                padding: .25rem .6rem;
                background: #ffffff08
            }

            .footer {
                opacity: .7;
                font-size: .85rem
            }

            /* light mode */
            :root.light body {
                background: #f7fafc;
                color: #0b0c12
            }

            :root.light .glass {
                background: #ffffffb3;
                border-color: #00000014
            }

            :root.light .card {
                background: #ffffff;
                border-color: #00000014
            }

            :root.light .muted {
                color: #4b5563
            }
        </style>
    @endif
</head>

<body>
    <!-- animated background -->
    <div class="blob"></div>
    <div class="gridfx absolute inset-0"></div>

    <header class="container">
        <nav class="glass" style="padding:10px 16px; display:flex; align-items:center; justify-content:space-between">
            <div style="display:flex; align-items:center; gap:.75rem">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z" stroke="currentColor" opacity=".9" />
                </svg>
                <strong>Laravel • Welcome</strong>
                <span class="chip">Breeze Ready</span>
            </div>
            <div style="display:flex; gap:.5rem; align-items:center">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="btn ghost">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="btn ghost">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn primary">Register</a>
                        @endif
                    @endauth
                @endif
                <button id="themeToggle" class="btn ghost" title="Toggle theme" aria-label="Toggle theme">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path id="sun"
                            d="M12 4V2m0 20v-2m8-8h2M2 12H4m12.364-6.364 1.414-1.414M6.222 18.364l-1.414 1.414m12.97 0 1.414-1.414M4.808 6.222 3.394 4.808"
                            stroke="currentColor" stroke-linecap="round" />
                        <path id="moon" d="M20 12.5A7.5 7.5 0 0 1 11.5 4 8 8 0 1 0 20 12.5Z" stroke="currentColor"
                            opacity=".6" />
                    </svg>
                </button>
            </div>
        </nav>
    </header>

    <main class="container" style="position:relative; z-index:1">
        <!-- Hero -->
        <section class="glass"
            style="padding:28px; display:grid; grid-template-columns:1.1fr .9fr; gap:18px; align-items:center">
            <div>
                <div class="chip">Welcome aboard</div>
                <h1 style="margin:.5rem 0 0; font-size:clamp(28px,4vw,44px); line-height:1.15; font-weight:700">
                    Build faster with a beautiful dark starter
                </h1>
                <p class="muted" style="margin:.75rem 0 1.25rem">
                    Laravel ecosystem is huge. Start strong with docs, videos, and a production-ready layout.
                </p>
                <div style="display:flex; gap:.6rem; flex-wrap:wrap">
                    <a href="https://laravel.com/docs" target="_blank" class="btn primary">Read Docs</a>
                    <a href="https://laracasts.com" target="_blank" class="btn ghost">Watch Laracasts</a>
                    <a href="https://github.com/laravel/laravel" target="_blank" class="btn ghost">GitHub</a>
                </div>
            </div>
            <div class="glass"
                style="aspect-ratio: 16/10; border-radius:16px; display:grid; place-items:center; position:relative; overflow:hidden">
                <svg width="140" height="140" viewBox="0 0 100 100" fill="none" style="opacity:.95">
                    <defs>
                        <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#7c3aed" />
                            <stop offset="1" stop-color="#22d3ee" />
                        </linearGradient>
                    </defs>
                    <path d="M10 10h30v30H10zM60 10h30v30H60zM10 60h30v30H10zM60 60h30v30H60z" stroke="url(#g)"
                        stroke-width="6"></path>
                </svg>
                <div
                    style="position:absolute; inset:0; background:radial-gradient(600px 300px at 70% -10%, #ffffff22, transparent 60%);">
                </div>
            </div>
        </section>

        <!-- Cards -->
        <section style="margin-top:18px" class="cards">
            <article class="card">
                <h3 style="margin:0 0 .25rem">Documentation</h3>
                <p class="muted" style="margin:0 0 .75rem">Browse official guides, from routing to queues.</p>
                <a class="btn ghost" href="https://laravel.com/docs" target="_blank">Open Docs →</a>
            </article>
            <article class="card">
                <h3 style="margin:0 0 .25rem">Laracasts</h3>
                <p class="muted" style="margin:0 0 .75rem">Learn by watching concise, practical videos.</p>
                <a class="btn ghost" href="https://laracasts.com" target="_blank">Start Learning →</a>
            </article>
            <article class="card">
                <h3 style="margin:0 0 .25rem">Deploy</h3>
                <p class="muted" style="margin:0 0 .75rem">Ship to production with Forge, Vapor or your stack.</p>
                <a class="btn ghost" href="https://cloud.laravel.com" target="_blank">Deploy Now →</a>
            </article>
        </section>

        <section style="margin-top:22px" class="glass footer" dir="ltr">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 18px">
                <span>Made with ❤️ for dark mode fans.</span>
                <span>Laravel {{ app()->version() }}</span>
            </div>
        </section>
    </main>

    <script>
        document.getElementById('themeToggle')?.addEventListener('click', () => {
            const el = document.documentElement;
            const isDark = el.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    </script>
</body>

</html>
