<?php

namespace App\Console\Commands;

use App\Services\VisionDevices;
use Illuminate\Console\Command;

/**
 * Issue (or revoke) the Sanctum token a pool-vision camera posts pots with.
 * The /vision page can also pair a phone itself (VISION_PAIR_PASSWORD).
 */
class PoolVisionToken extends Command
{
    protected $signature = 'pool-vision:token
        {name=table : A name for this camera, e.g. "table-1"}
        {--revoke : Revoke this camera\'s tokens instead of issuing a new one}';

    protected $description = 'Issue or revoke the API token for a pool-vision camera';

    public function handle(VisionDevices $devices): int
    {
        $name = $this->argument('name');

        if ($this->option('revoke')) {
            $count = $devices->revoke($name);
            $this->info("Revoked {$count} token(s) for pool-vision:{$name}.");

            return self::SUCCESS;
        }

        $token = $devices->issueToken($name);

        $this->info("Token for pool-vision:{$name} (shown once; store it on the camera):");
        $this->line($token);
        $this->newLine();
        $this->line('On the camera laptop:');
        $this->line('  POOL_QUEUE_URL='.rtrim((string) config('app.url'), '/'));
        $this->line("  POOL_QUEUE_TOKEN={$token}");

        return self::SUCCESS;
    }
}
