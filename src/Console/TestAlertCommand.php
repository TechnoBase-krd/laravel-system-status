<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Technobase\SystemStatus\Support\StatusAlerter;

class TestAlertCommand extends Command
{
    protected $signature = 'status:test-alert
                            {--channel=* : Only these channels (telegram, mail, slack, discord)}
                            {--webhooks : Also POST a test event to alerts.webhooks}
                            {--force : Send even when alerts are off or in the local environment}';

    protected $description = 'Send a test System Status alert through each configured channel and show the result.';

    public function handle(StatusAlerter $alerter): int
    {
        $channels = $this->option('channel') ?: $alerter->channels();
        $text = '🧪 Test alert from '.config('app.name').' ('.app()->environment().'). If you can read this, System Status alerts reach you.';

        $results = $alerter->send($text, $channels, (bool) $this->option('force'));

        if ($this->option('webhooks')) {
            foreach ($alerter->event('test', ['message' => $text], (bool) $this->option('force')) as $url => $result) {
                $results['webhook '.parse_url($url, PHP_URL_HOST)] = $result;
            }
        }

        $this->table(['Channel', 'Result'], collect($results)->map(fn ($result, $channel) => [$channel, $result])->values()->all());

        if (collect($results)->contains(fn ($result) => str_starts_with($result, 'failed'))) {
            return self::FAILURE;
        }

        if (! collect($results)->contains('sent')) {
            $this->warn('Nothing was sent. Configure a channel, or use --force in the local environment.');
        }

        return self::SUCCESS;
    }
}
