{{--
    Tailwind for the status page, compiled in the browser by the Tailwind CDN script
    (system-status.tailwind_cdn). The page often lives inside an application's own layout
    (Bootstrap or anything else), so this setup:
     - prefixes every class: tw:flex, tw:rounded-xl, tw:hover:bg-gray-50;
     - loads no global preflight; the small reset below only touches .sst-ui elements;
     - makes utilities !important, so the host layout's styles cannot override them.
--}}
@if(filled(config('system-status.tailwind_cdn')))
    <style type="text/tailwindcss">
        @layer theme, base, components, utilities;
        @import "tailwindcss/theme.css" layer(theme) prefix(tw);
        @import "tailwindcss/utilities.css" layer(utilities) important;

        @custom-variant dark (&:where([data-theme=dark] *, .dark *));

        /* `hidden` must win over the !important display utilities: an !important rule in an
           earlier layer (base) beats one in a later layer (utilities). */
        @layer base {
            .sst [hidden],
            .sst-ui[hidden],
            .sst-ui [hidden] {
                display: none !important;
            }
        }

        /* Scoped reset for the dialogs, panels, menus and forms built with these utilities. */
        .sst-ui,
        .sst-ui *,
        .sst-ui *::before,
        .sst-ui *::after {
            box-sizing: border-box;
            border-width: 0;
            border-style: solid;
            border-color: var(--tw-color-gray-200, #e5e7eb);
        }

        .sst-ui :where(button, input, select, textarea) {
            font: inherit;
            color: inherit;
            letter-spacing: inherit;
            margin: 0;
        }

        .sst-ui :where(button) {
            background: transparent;
            cursor: pointer;
        }

        .sst-ui :where(h2, h3, h4, p, ol, ul, fieldset, legend) {
            margin: 0;
            padding: 0;
        }

        .sst-ui :where(ol, ul) {
            list-style: none;
        }

        .sst-ui :where(table) {
            border-collapse: collapse;
        }
    </style>
    <script src="{{ config('system-status.tailwind_cdn') }}"></script>
@endif
