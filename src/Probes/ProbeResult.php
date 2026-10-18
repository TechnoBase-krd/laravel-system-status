<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Str;
use Technobase\SystemStatus\Enums\ComponentStatus;

/**
 * The outcome of one probe run. A null status means the probe was skipped (the integration is
 * disabled or not set up); a skipped probe does not affect the component.
 */
final class ProbeResult
{
    public function __construct(
        public readonly ?ComponentStatus $status,
        public readonly ?string $message = null,
        public ?int $responseMs = null,
    ) {}

    public static function ok(?string $message = null): self
    {
        return new self(ComponentStatus::Operational, $message);
    }

    public static function degraded(string $message): self
    {
        return new self(ComponentStatus::Degraded, $message);
    }

    public static function outage(string $message): self
    {
        return new self(ComponentStatus::MajorOutage, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(null, $message);
    }

    public function isSkipped(): bool
    {
        return $this->status === null;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status?->value,
            'message' => $this->message !== null ? Str::limit($this->message, 480) : null,
            'response_ms' => $this->responseMs,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['status']) ? ComponentStatus::tryFrom($data['status']) : null,
            $data['message'] ?? null,
            $data['response_ms'] ?? null,
        );
    }
}
