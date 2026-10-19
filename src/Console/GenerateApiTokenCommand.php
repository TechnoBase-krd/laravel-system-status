<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateApiTokenCommand extends Command
{
    protected $signature = 'status:api-token';

    protected $description = 'Generate a token for the System Status JSON API and show the .env lines to add.';

    public function handle(): int
    {
        $token = Str::random(64);

        $this->line('Add these lines to .env, then give the same token to the central dashboard:');
        $this->newLine();
        $this->line('SYSTEM_STATUS_API_ENABLED=true');
        $this->line('SYSTEM_STATUS_API_TOKEN='.$token);
        $this->newLine();

        $path = trim((string) config('system-status.path', 'system-status'), '/');
        $this->line('Endpoint: '.rtrim((string) config('app.url'), '/').'/'.$path.'/api/summary');

        return self::SUCCESS;
    }
}
