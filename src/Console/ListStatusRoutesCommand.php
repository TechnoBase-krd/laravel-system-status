<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Technobase\SystemStatus\Support\RouteComponentResolver;

class ListStatusRoutesCommand extends Command
{
    protected $signature = 'status:routes
                            {--component= : Only routes of this component}
                            {--fallback : Only routes that no rule matched (counted under api_other / bo_other)}';

    protected $description = 'Show which System Status component every route is counted under.';

    public function handle(Router $router, RouteComponentResolver $resolver): int
    {
        $fallbackComponents = array_values(config('system-status.fallback', []));
        $rows = [];
        $perComponent = [];

        foreach ($router->getRoutes() as $route) {
            $component = $resolver->resolve($route->getName(), $route->uri()) ?? '(ignored)';
            $perComponent[$component] = ($perComponent[$component] ?? 0) + 1;

            if ($this->option('component') && $this->option('component') !== $component) {
                continue;
            }

            if ($this->option('fallback') && ! in_array($component, $fallbackComponents, true)) {
                continue;
            }

            $rows[] = [$component, implode('|', $route->methods()), $route->uri(), $route->getName() ?? ''];
        }

        usort($rows, fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        $this->table(['Component', 'Method', 'URI', 'Name'], $rows);

        ksort($perComponent);
        $this->table(['Component', 'Routes'], array_map(fn ($c, $n) => [$c, $n], array_keys($perComponent), $perComponent));

        return self::SUCCESS;
    }
}
