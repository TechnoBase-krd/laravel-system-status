<?php

namespace Technobase\SystemStatus\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Health\HealthServiceProvider;
use Technobase\SystemStatus\SystemStatusServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [HealthServiceProvider::class, SystemStatusServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::loadLocalEnv();

        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', match ($driver) {
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'system_status_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'system_status_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        });

        $config->set('database.redis.client', getenv('REDIS_CLIENT') ?: 'phpredis');
        $config->set('database.redis.options.prefix', 'system_status_test:');
        $config->set('database.redis.default', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => getenv('REDIS_PORT') ?: '6379',
            'database' => getenv('REDIS_DB') ?: '15',
        ]);

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('auth.providers.users.model', Fixtures\User::class);
        $config->set('system-status.layout', 'standalone');
        $config->set('system-status.schedule', false);
        $config->set('system-status.queues', []);
        $config->set('system-status.groups', [
            'platform' => [
                'database' => [],
                'scheduler' => [],
            ],
            'api' => [
                'api_orders' => ['uris' => ['api/orders*']],
                'api_other' => [],
            ],
            'backoffice' => [
                'bo_other' => [],
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('role')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function flushRedis(): void
    {
        try {
            $redis = Redis::connection();
            foreach ($redis->keys('status:*') as $key) {
                $redis->del(str_replace('system_status_test:', '', $key));
            }
        } catch (\Throwable) {
            // Redis is optional for most tests.
        }
    }

    /**
     * Reads tests/.env.testing (git-ignored) for local database credentials.
     */
    private static function loadLocalEnv(): void
    {
        $file = __DIR__.'/.env.testing';

        if (! is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (getenv($key) === false) {
                putenv($key.'='.trim($value, '"\''));
            }
        }
    }
}
