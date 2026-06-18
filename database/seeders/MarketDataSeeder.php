<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\BusinessType;
use App\Models\CarBrand;
use App\Models\PartCategory;

class MarketDataSeeder extends Seeder
{
    public function run()
    {
        $businessTypes = ['قطع غيار جديدة', 'قطع غيار مستعملة/كسر', 'إطارات وجنطات', 'بطاريات وزيوت', 'اكسسوارات وزينة'];
        foreach ($businessTypes as $type) {
            BusinessType::create(['name' => $type]);
        }
        
        $brands = ['Toyota', 'Mercedes-Benz', 'Hyundai', 'Kia', 'BMW', 'Mazda', 'Volkswagen', 'Chevrolet'];
        foreach ($brands as $brand) {
            CarBrand::create(['name' => $brand]);
        }

        $categories = ['ميكانيك عام', 'كهرباء ونظام إنارة', 'فلاتر وسيور', 'فرامل وديسكات', 'عفشة وهيدروليك', 'هيكل خارجي وصاج'];
        foreach ($categories as $category) {
            PartCategory::create(['name' => $category]);
        }
    }
}