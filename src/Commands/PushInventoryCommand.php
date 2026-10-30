<?php

namespace MonkeySoft\SitesMonkey\Commands;

use Illuminate\Console\Command;
use MonkeySoft\SitesMonkey\Exceptions\InventoryException;
use MonkeySoft\SitesMonkey\Inventory\InventoryCollector;
use MonkeySoft\SitesMonkey\Inventory\InventoryPusher;

class PushInventoryCommand extends Command
{
    public $signature = 'sitesmonkey:inventory:push
                        {--dry-run : Show what would be sent, without sending it}';

    public $description = 'Send the list of installed packages to SitesMonkey for vulnerability monitoring';

    public function handle(InventoryCollector $collector, InventoryPusher $pusher): int
    {
        if ($this->option('dry-run')) {
            return $this->dryRun($collector);
        }

        $result = $pusher->push();

        foreach ($collector->warnings as $warning) {
            $this->warn($warning);
        }

        if ($result['success']) {
            $this->info('Inventory sent to SitesMonkey.');

            return self::SUCCESS;
        }

        $this->error(match ($result['reason']) {
            'not_configured' => 'SitesMonkey inventory is not configured. Set SITESMONKEY_WEBSITE_ID, SITESMONKEY_INVENTORY_KEY_ID and SITESMONKEY_INVENTORY_KEY_SECRET.',
            'collection_failed' => 'The installed packages could not be determined, so nothing was sent.',
            'locked' => 'Another inventory push is already running.',
            'rejected' => 'SitesMonkey did not accept the inventory (HTTP '.$result['http_status'].').',
            default => 'SitesMonkey could not be reached.',
        });

        return self::FAILURE;
    }

    private function dryRun(InventoryCollector $collector): int
    {
        try {
            $payload = $collector->collect();
        } catch (InventoryException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($collector->warnings as $warning) {
            $this->warn($warning);
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
