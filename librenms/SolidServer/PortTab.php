<?php

namespace App\Plugins\SolidServer;

use App\Plugins\Hooks\PortTabHook;
use App\Models\Port;
use Illuminate\Contracts\Auth\Authenticatable;

class PortTab extends PortTabHook
{
    public function authorize(Authenticatable $user, Port $port): bool
    {
        return false;
    }
}
