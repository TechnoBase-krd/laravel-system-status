{{-- A form field people never see or fill; a bot fills it and becomes a critical finding.
     Use inside a form: <x-system-status::honeypot /> (field name: security.honeypot.field). --}}
@php($field = config('system-status.security.honeypot.field', 'website_url'))
@if (filled($field))
    <div aria-hidden="true" style="position:absolute!important;inset-inline-start:-10000px!important;inline-size:1px;block-size:1px;overflow:hidden">
        <label for="{{ $field }}_{{ $uid = substr(md5(uniqid('', true)), 0, 6) }}">Leave this empty</label>
        <input type="text" name="{{ $field }}" id="{{ $field }}_{{ $uid }}" value="" tabindex="-1" autocomplete="off">
    </div>
@endif
