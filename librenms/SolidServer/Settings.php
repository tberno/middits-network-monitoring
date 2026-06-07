<?php

namespace App\Plugins\SolidServer;

use App\Plugins\Hooks\SettingsHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Settings extends SettingsHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('admin');
    }

    public function data(array $settings = []): array
    {
        return [
            'settings' => $settings,
        ];
    }
}
