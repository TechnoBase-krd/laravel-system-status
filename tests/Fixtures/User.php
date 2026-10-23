<?php

namespace Technobase\SystemStatus\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    public function hasRole(array|string $roles): bool
    {
        return in_array($this->role, (array) $roles, true);
    }
}
