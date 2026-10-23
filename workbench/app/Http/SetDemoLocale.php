<?php

namespace Workbench\App\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * ?locale=ar (or ku, en) switches the demo's language and remembers it in the session.
 */
class SetDemoLocale
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($locale = $request->query('locale'), ['en', 'ar', 'ku'], true)) {
            $request->session()->put('demo_locale', $locale);
        }

        app()->setLocale($request->session()->get('demo_locale', 'en'));

        return $next($request);
    }
}
