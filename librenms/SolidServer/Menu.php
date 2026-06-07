<?php

namespace App\Plugins\SolidServer;

use App\Plugins\Hooks\MenuEntryHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Menu extends MenuEntryHook
{
    public function authorize(Authenticatable $user, array $settings = []): bool
    {
        return $user->can('global-read');
    }
}
