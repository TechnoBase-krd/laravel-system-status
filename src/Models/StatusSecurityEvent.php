<?php

namespace Technobase\SystemStatus\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * One suspicious activity finding (security.*): a rule that was crossed by an IP, an account,
 * a signal or a user.
 *
 * @property string $category request, login, signal or action
 * @property string $type
 * @property ?string $rule
 * @property ?string $ip
 * @property ?string $subject
 * @property ?string $country
 * @property int $hits
 * @property ?int $window_minutes
 * @property ?int $max
 * @property ?array<string, mixed> $details
 * @property CarbonInterface $detected_at
 * @property CarbonInterface $last_seen_at
 */
class StatusSecurityEvent extends Model
{
    public const CATEGORY_REQUEST = 'request';

    public const CATEGORY_LOGIN = 'login';

    public const CATEGORY_SIGNAL = 'signal';

    public const CATEGORY_ACTION = 'action';

    protected $fillable = [
        'category', 'type', 'rule', 'ip', 'subject', 'country', 'hits', 'window_minutes', 'max',
        'details', 'detected_at', 'last_seen_at',
    ];

    protected $casts = [
        'hits' => 'integer',
        'window_minutes' => 'integer',
        'max' => 'integer',
        'details' => 'array',
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * One sentence in $locale, e.g. "34 denied requests (401, 403, 419) from 203.0.113.9 in 5 min".
     * A request rule without its own text uses the generic one.
     */
    public function describe(?string $locale = null): string
    {
        $key = 'system-status::status.status_security_'.$this->type;

        if (! Lang::has($key, $locale, false)) {
            $key = 'system-status::status.status_security_'.$this->category;
        }

        return __($key, [
            'hits' => number_format($this->hits),
            'minutes' => (string) ($this->window_minutes ?? ''),
            'max' => (string) ($this->max ?? ''),
            'ip' => (string) ($this->ip ?? '?'),
            'subject' => (string) ($this->subject ?? '?'),
            'rule' => (string) ($this->rule ?? $this->type),
            'country' => (string) ($this->country ?? '?'),
        ], $locale);
    }

    /**
     * Font Awesome icon for the page.
     */
    public function icon(): string
    {
        return match ($this->category) {
            self::CATEGORY_LOGIN => 'fa-user-lock',
            self::CATEGORY_SIGNAL => 'fa-coins',
            self::CATEGORY_ACTION => 'fa-user-shield',
            default => match ($this->type) {
                'scanner' => 'fa-bug',
                'injection' => 'fa-syringe',
                'scanner_tools' => 'fa-robot',
                'rate_limited' => 'fa-tachometer-alt',
                default => 'fa-shield-alt',
            },
        };
    }
}
