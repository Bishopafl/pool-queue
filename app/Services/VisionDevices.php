<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The cameras allowed to post pots. Each camera gets a Sanctum token with the
 * "vision" ability, owned by one device user that can't sign in to anything.
 */
class VisionDevices
{
    public const DEVICE_EMAIL = 'pool-vision@devices.invalid';

    public function issueToken(string $name): string
    {
        return $this->deviceUser()->createToken($this->tokenName($name), ['vision'])->plainTextToken;
    }

    public function revoke(string $name): int
    {
        return $this->deviceUser()->tokens()->where('name', $this->tokenName($name))->delete();
    }

    private function deviceUser(): User
    {
        return User::firstOrCreate(
            ['email' => self::DEVICE_EMAIL],
            ['name' => 'pool-vision camera', 'password' => Str::random(64)],
        );
    }

    private function tokenName(string $name): string
    {
        return 'pool-vision:'.$name;
    }
}
