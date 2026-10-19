{{--
    The package's own full page, used when `system-status.layout` is 'standalone' or null, or
    when the configured layout view does not exist. It loads what the page needs (Bootstrap 4,
    jQuery, Font Awesome) from `system-status.standalone.css` / `.js`, so any Laravel
    application can show the page without a backoffice layout.

    The top bar mirrors the page's overall status (#sstHero data-level and title) in a live pill
    with a pulsing dot and a heartbeat line. Animations use only transform and opacity, and the
    bar has no backdrop-filter, so nothing repaints the page while it scrolls.
--}}
@php
    $locale = str_replace('_', '-', app()->getLocale());
    $rtl = in_array(strtok($locale, '-'), (array) config('system-status.standalone.rtl_locales', ['ar', 'ku', 'ckb', 'fa', 'he', 'ur']), true);
    $brand = config('system-status.standalone.brand') ?: 'TechnoBase';
    $backUrl = url(config('system-status.standalone.back_url') ?: '/');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $brand }}</title>

    @foreach ((array) config('system-status.standalone.css', []) as $href)
        <link rel="stylesheet" href="{{ $href }}">
    @endforeach

    <style>
        body.sst-standalone {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(1200px 400px at 50% -200px, rgb(99 102 241 / .08), transparent 70%),
                #f5f6f8;
            color: #111827;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", Tahoma, sans-serif;
        }

        /* ---------- top bar ---------- */
        .sst-bar {
            --bar-tone: #9ca3af;
            position: sticky;
            top: 0;
            z-index: 1030;
            background: #fff;
            border-bottom: 1px solid rgb(17 24 39 / .07);
            box-shadow: 0 6px 24px -18px rgb(17 24 39 / .35);
        }

        .sst-bar[data-level="operational"] { --bar-tone: #16a34a; }
        .sst-bar[data-level="degraded"] { --bar-tone: #f59e0b; }
        .sst-bar[data-level="partial"] { --bar-tone: #f97316; }
        .sst-bar[data-level="major"] { --bar-tone: #dc2626; }

        /* A thin line in the status colour under the bar. */
        .sst-bar::after {
            content: "";
            position: absolute;
            inset-inline: 0;
            bottom: -1px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--bar-tone), transparent);
            opacity: .7;
            transition: background .4s ease;
        }

        .sst-bar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            max-width: 1280px;
            margin: 0 auto;
            padding: .75rem 1rem;
        }

        .sst-bar-start {
            display: flex;
            flex: none;
            align-items: center;
            gap: .875rem;
        }

        .sst-back {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            height: 2.25rem;
            padding: 0 .875rem;
            border: 1px solid rgb(17 24 39 / .1);
            border-radius: 999px;
            background: #fff;
            color: #374151;
            font-size: .875rem;
            font-weight: 500;
            box-shadow: 0 1px 2px rgb(16 24 40 / .05);
            transition: background .2s ease, border-color .2s ease, color .2s ease;
        }

        .sst-back:hover,
        .sst-back:focus-visible {
            background: #f9fafb;
            border-color: rgb(17 24 39 / .18);
            color: #111827;
            text-decoration: none;
        }

        .sst-back i {
            transition: transform .2s cubic-bezier(.2, .8, .2, 1);
        }

        .sst-back:hover i { transform: translateX(-3px); }
        [dir="rtl"] .sst-back:hover i { transform: translateX(3px); }

        .sst-bar-divider {
            width: 1px;
            height: 1.75rem;
            background: rgb(17 24 39 / .1);
        }

        .sst-brand {
            display: flex;
            align-items: center;
            gap: .625rem;
            min-width: 0;
        }

        .sst-brand-mark {
            display: grid;
            place-items: center;
            flex: none;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .625rem;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            color: #fff;
            box-shadow: 0 4px 14px -4px rgb(79 70 229 / .55);
        }

        .sst-brand-mark i {
            animation: sst-heartbeat 1.6s ease-in-out infinite;
            will-change: transform;
        }

        .sst-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            min-width: 0;
        }

        .sst-brand-name {
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: -.01em;
        }

        .sst-brand-page {
            font-size: .75rem;
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ---------- live status pill ---------- */
        .sst-live {
            display: inline-flex;
            align-items: center;
            gap: .625rem;
            height: 2.25rem;
            padding: 0 .875rem 0 .75rem;
            border-radius: 999px;
            background: color-mix(in oklab, var(--bar-tone) 10%, #fff);
            border: 1px solid color-mix(in oklab, var(--bar-tone) 30%, transparent);
            color: color-mix(in oklab, var(--bar-tone) 70%, #111827);
            font-size: .8125rem;
            font-weight: 600;
            white-space: nowrap;
            contain: layout paint;
        }

        .sst-pulse {
            position: relative;
            flex: none;
            width: .625rem;
            height: .625rem;
            border-radius: 50%;
            background: var(--bar-tone);
            transition: background .4s ease;
        }

        .sst-pulse::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: var(--bar-tone);
            animation: sst-ping 2s cubic-bezier(0, 0, .2, 1) infinite;
            will-change: transform, opacity;
        }

        /* Faster pulse while loading or when something is wrong. */
        .sst-bar[data-level="loading"] .sst-pulse::after,
        .sst-bar[data-level="partial"] .sst-pulse::after,
        .sst-bar[data-level="major"] .sst-pulse::after { animation-duration: 1.1s; }

        .sst-ecg {
            position: relative;
            flex: none;
            width: 4.5rem;
            height: 1.25rem;
            overflow: hidden;
            color: var(--bar-tone);
            -webkit-mask-image: linear-gradient(90deg, transparent, #000 25%, #000 75%, transparent);
            mask-image: linear-gradient(90deg, transparent, #000 25%, #000 75%, transparent);
        }

        .sst-ecg svg {
            display: block;
            width: 9rem;
            height: 1.25rem;
            animation: sst-trace 2.4s linear infinite;
            will-change: transform;
        }

        .sst-ecg path {
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        [dir="rtl"] .sst-ecg { transform: scaleX(-1); }

        .sst-standalone-main {
            max-width: 1280px;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem;
        }

        [dir="rtl"] .sst-standalone .close {
            float: left;
            margin: -1rem auto -1rem -1rem;
        }

        @keyframes sst-ping {
            0% { transform: scale(1); opacity: .6; }
            80%, 100% { transform: scale(2.8); opacity: 0; }
        }

        @keyframes sst-heartbeat {
            0%, 40%, 100% { transform: scale(1); }
            10% { transform: scale(1.18); }
            20% { transform: scale(.96); }
            30% { transform: scale(1.1); }
        }

        @keyframes sst-trace {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }

        @media (max-width: 575.98px) {
            .sst-back span,
            .sst-bar-divider,
            .sst-ecg,
            .sst-brand-page { display: none; }

            .sst-back { width: 2.25rem; padding: 0; justify-content: center; }
            .sst-bar-inner { gap: .5rem; }
            .sst-live { min-width: 0; flex: 0 1 auto; padding: 0 .75rem; }
            .sst-live-text { display: inline-block; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        }

        @media (prefers-reduced-motion: reduce) {
            .sst-pulse::after,
            .sst-brand-mark i,
            .sst-ecg svg { animation: none; }
        }
    </style>
    @stack(config('system-status.stacks.styles', 'styles'))
</head>
<body class="sst-standalone">
    <header class="sst-bar" id="sstBar" data-level="loading">
        <div class="sst-bar-inner">
            <div class="sst-bar-start">
                <a href="{{ $backUrl }}" class="sst-back" id="sstBack" aria-label="{{ __('system-status::status.back') }}">
                    <i class="fas {{ $rtl ? 'fa-arrow-right' : 'fa-arrow-left' }}" aria-hidden="true"></i>
                    <span>@lang('system-status::status.back')</span>
                </a>
                <span class="sst-bar-divider" aria-hidden="true"></span>
                <div class="sst-brand">
                    <span class="sst-brand-mark" aria-hidden="true"><i class="fas fa-heartbeat"></i></span>
                    <span class="sst-brand-text">
                        <span class="sst-brand-name">{{ $brand }}</span>
                        <span class="sst-brand-page">@yield('title')</span>
                    </span>
                </div>
            </div>

            <div class="sst-live" aria-live="polite">
                <span class="sst-ecg" aria-hidden="true">
                    <svg viewBox="0 0 144 20" preserveAspectRatio="none">
                        <path d="M0 10h18l4-6 5 13 5-16 4 9h36h18l4-6 5 13 5-16 4 9h36"/>
                    </svg>
                </span>
                <span class="sst-pulse" aria-hidden="true"></span>
                <span class="sst-live-text" id="sstBarText">@lang('system-status::status.status_loading')</span>
            </div>
        </div>
    </header>

    <main class="sst-standalone-main">
        @yield('content')
    </main>

    @stack(config('system-status.stacks.modals', 'modals'))

    @foreach ((array) config('system-status.standalone.js', []) as $src)
        <script src="{{ $src }}"></script>
    @endforeach
    <script>
        (function () {
            'use strict';

            // Back to the previous page of the application when there is one, otherwise back_url.
            document.getElementById('sstBack').addEventListener('click', function (event) {
                if (document.referrer.indexOf(window.location.origin + '/') === 0 && window.history.length > 1) {
                    event.preventDefault();
                    window.history.back();
                }
            });

            var bar = document.getElementById('sstBar');

            // Mirror the overall status the page shows in its hero.
            var hero = document.getElementById('sstHero');
            var heroTitle = document.getElementById('sstHeroTitle');
            var barText = document.getElementById('sstBarText');

            if (!hero || !heroTitle) {
                return;
            }

            function sync() {
                var level = hero.dataset.level || 'loading';
                var text = heroTitle.textContent.trim();

                if (bar.dataset.level !== level) {
                    bar.dataset.level = level;
                }
                if (barText.textContent !== text) {
                    barText.textContent = text;
                }
            }

            sync();
            new MutationObserver(sync).observe(hero, {attributes: true, attributeFilter: ['data-level']});
            new MutationObserver(sync).observe(heroTitle, {childList: true, characterData: true, subtree: true});
        })();
    </script>
    @stack(config('system-status.stacks.scripts', 'scripts'))
</body>
</html>
