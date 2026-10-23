<?php

namespace Technobase\SystemStatus\Tests\Fixtures;

class InvokableCheck
{
    public static mixed $result = true;

    public function __invoke(): mixed
    {
        return self::$result;
    }

    public function check(): mixed
    {
        return 'slow but working';
    }
}
