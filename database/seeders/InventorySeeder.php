<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $dateIn = now()->subDays(14)->startOfDay();

        foreach ($this->products() as [$name, $quantity, $expiryMonths]) {
            if (InventoryItem::withTrashed()->where('name', $name)->exists()) {
                continue;
            }

            InventoryItem::create([
                'name' => $name,
                'date_in' => $dateIn->toDateString(),
                'expiry_date' => $expiryMonths === null
                    ? null
                    : $dateIn->copy()->addMonths($expiryMonths)->toDateString(),
                'quantity' => $quantity,
                'unit' => InventoryItem::DEFAULT_UNIT,
                'category' => InventoryItem::DEFAULT_CATEGORY,
            ]);
        }
    }

    /**
     * @return array<int, array{0: string, 1: int, 2: int|null}>
     */
    private function products(): array
    {
        return [
            ['Acetone / Nail Polish Remover', 30, 24],
            ['Nail Polish', 30, 24],
            ['Gel Polish', 20, 24],
            ['Gel Base Coat', 15, 24],
            ['Gel Top Coat', 15, 24],
            ['Nail Primer', 15, 24],
            ['Cuticle Oil', 20, 24],
            ['Cuticle Remover', 15, 24],
            ['Nail Cleanser', 20, 24],
            ['Foot Scrub', 15, 24],
            ['Foot Soak', 15, 24],
            ['Paraffin Wax', 10, 24],
            ['Nail File', 30, null],
            ['Nail Buffer', 30, null],
            ['Disposable Pedicure Foot Liners', 30, null],

            ['Nail Extension Gel', 15, 24],
            ['Nail Extension Tips', 20, null],
            ['Nail Glue', 15, 12],
            ['Nail Art Rhinestones', 20, null],
            ['Nail Art Stickers', 20, 24],
            ['Chrome Nail Powder', 15, 24],
            ['Ombre Nail Powder', 15, 24],
            ['Nail Art Brushes', 15, null],
            ['Nail Forms', 20, null],
            ['Nail Extension Remover', 15, 24],

            ['Facial Cleanser', 15, 24],
            ['Facial Toner', 15, 24],
            ['Facial Moisturizer', 15, 24],
            ['Facial Mask', 20, 24],
            ['Exfoliating Scrub', 15, 24],
            ['Body Scrub', 15, 24],
            ['Body Lotion', 20, 24],
            ['Massage Cream', 15, 24],
            ['Aromatherapy Essential Oil', 15, 24],
            ['Disposable Facial Headbands', 30, null],

            ['Eyelash Extensions', 10, null],
            ['Eyelash Extension Glue', 10, 12],
            ['Eyelash Remover', 10, 24],
            ['Eyelash Shampoo', 15, 24],
            ['Eyebrow Tint', 15, 24],
            ['Eyebrow Wax', 15, 24],
            ['Brow Lamination Solution', 10, 12],
            ['Disposable Mascara Wands', 30, null],
            ['Under-Eye Pads', 30, null],
            ['Lash Lift Solution', 10, 12],

            ['Massage Oil', 20, 24],
            ['Massage Lotion', 20, 24],
            ['Hot Stone Massage Stones', 10, null],
            ['Ventosa Cups', 10, null],
            ['Aromatherapy Oil', 15, 24],
            ['Disposable Massage Bed Sheets', 30, null],
            ['Massage Towels', 20, null],

            ['Shampoo', 30, 24],
            ['Conditioner', 30, 24],
            ['Hair Treatment Cream', 20, 24],
            ['Hair Color', 20, 24],
            ['Hair Bleach Powder', 15, 24],
            ['Hair Developer', 20, 12],
            ['Keratin Treatment', 10, 24],
            ['Hair Rebonding Cream', 10, 24],
            ['Hair Serum', 20, 24],
            ['Hair Mask', 15, 24],
            ['Hair Spa Treatment', 15, 24],
            ['Hair Styling Gel', 15, 24],
            ['Hair Spray', 15, 24],

            ['Kiddie Shampoo', 15, 24],
            ['Kiddie Conditioner', 15, 24],
            ['Kiddie Lotion', 15, 24],
            ['Kiddie Nail Polish', 20, 24],
            ['Gentle Facial Cleanser', 15, 24],

            ['Hand Cream', 20, 24],
            ['Foot Cream', 20, 24],
            ['Paraffin Hand Treatment Wax', 10, 24],
            ['Paraffin Foot Treatment Wax', 10, 24],
            ['Nail Strengthener', 15, 24],

            ['Disposable Gloves', 30, 36],
            ['Face Masks', 30, 36],
            ['Cotton Balls', 30, null],
            ['Cotton Pads', 30, null],
            ['Tissue Rolls', 30, null],
            ['Alcohol / Sanitizer', 20, 24],
            ['Disinfectant Solution', 20, 24],
            ['Disposable Wooden Spatulas', 30, null],
            ['Disposable Applicators', 30, null],
            ['Sterilization Pouches', 30, null],
        ];
    }
}
