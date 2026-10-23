<?php

use Technobase\SystemStatus\Support\RouteComponentResolver;

it('maps routes to components with fallbacks and ignores', function () {
    $resolver = new RouteComponentResolver;

    expect($resolver->resolve(null, 'api/orders/5'))->toBe('api_orders')
        ->and($resolver->resolve(null, 'api/unknown'))->toBe('api_other')
        ->and($resolver->resolve(null, 'admin/products'))->toBe('bo_other')
        ->and($resolver->resolve(null, 'horizon/api'))->toBeNull();
});
