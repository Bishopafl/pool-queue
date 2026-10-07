<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issue (or revoke) the Sanctum token the pool-vision camera posts pots with.
 * Tokens belong to a dedicated device user that can't sign in to the web app.
 */
class PoolVisionToken extends Command
{
    public const DEVICE_EMAIL = 'pool-vision@devices.invalid';

    protected $signature = 'pool-vision:token
        {name=table : A name for this camera, e.g. "table-1"}
        {--revoke : Revoke this camera\'s tokens instead of issuing a new one}';

    protected $description = 'Issue or revoke the API token for a pool-vision camera';

    public function handle(): int
    {
        $device = User::firstOrCreate(
            ['email' => self::DEVICE_EMAIL],
            ['name' => 'pool-vision camera', 'password' => Str::random(64)],
        );
        $name = 'pool-vision:'.$this->argument('name');

        if ($this->option('revoke')) {
            $count = $device->tokens()->where('name', $name)->delete();
            $this->info("Revoked {$count} token(s) for {$name}.");

            return self::SUCCESS;
        }

        $token = $device->createToken($name, ['vision'])->plainTextToken;

        $this->info("Token for {$name} (shown once; store it on the camera laptop):");
        $this->line($token);
        $this->newLine();
        $this->line('On the camera laptop:');
        $this->line('  POOL_QUEUE_URL='.rtrim((string) config('app.url'), '/'));
        $this->line("  POOL_QUEUE_TOKEN={$token}");

        return self::SUCCESS;
    }
}
