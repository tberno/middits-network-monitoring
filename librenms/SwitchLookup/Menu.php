<?php

namespace App\Plugins\SwitchLookup;

use App\Plugins\Hooks\MenuEntryHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Menu extends MenuEntryHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('global-read');
    }
}
