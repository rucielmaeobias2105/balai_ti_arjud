<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empties the stockroom without touching anything else.
 *
 * The inventory seeder is run on demand rather than as part of the default seed
 * run, but a database that has had it run still holds those rows, and the
 * low-stock badge on the sidebar counts them. This is the one-off cleanup for
 * that: it removes the items and the service↔item links, and deliberately
 * nothing else — services, customers, appointments and every other table are
 * left exactly as they are.
 *
 * Guarded behind --force, because deleting stock records is not something to
 * do by accident.
 */
class ClearInventoryCommand extends Command
{
    protected $signature = 'inventory:clear
                            {--force : Actually delete the rows, not just report what would go}
                            {--dry-run : List the items that would be removed and stop}';

    protected $description = 'Remove every inventory item and service link, leaving all other data alone.';

    public function handle(): int
    {
        $items = InventoryItem::withTrashed()->count();
        $links = DB::table('service_inventory')->count();

        if ($items === 0 && $links === 0) {
            $this->info('Inventory is already empty. Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Item', 'SKU', 'Quantity'],
                InventoryItem::withTrashed()->get()->map(fn (InventoryItem $item) => [
                    $item->name,
                    $item->sku,
                    $item->stock_label,
                ])->all()
            );

            $this->info("{$items} item(s) and {$links} service link(s) would be removed. Re-run with --force to do it.");

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("This would permanently remove {$items} inventory item(s) and {$links} service link(s).");
            $this->warn('Re-run with --force to confirm, or --dry-run to see the list first.');

            return self::SUCCESS;
        }

        // Hard deletes, not soft ones: a soft-deleted item is still excluded
        // from the live list, but it stays in the table and keeps counting
        // toward the reports' low-stock total, which defeats the point.
        DB::transaction(function () {
            DB::table('service_inventory')->delete();
            DB::table('inventory_items')->delete();
        });

        $this->info("Removed {$items} inventory item(s) and {$links} service link(s).");
        $this->line('Other tables were not touched.');

        return self::SUCCESS;
    }
}
