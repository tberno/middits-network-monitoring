<?php

namespace App\Plugins\SolidServer;

use App\Plugins\Hooks\DeviceOverviewHook;
use App\Models\Device;
use Illuminate\Contracts\Auth\Authenticatable;

class DeviceOverview extends DeviceOverviewHook
{
    public function authorize(Authenticatable $user, Device $device): bool
    {
        return false;
    }
}
