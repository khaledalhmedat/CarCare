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
        $businessTypes = [
            1 => 'قطع غيار مستعملة/كسر',
            2 => 'إطارات وجنطات',
            3 => 'قطع غيار جديدة',
            4 => 'بطاريات وزيوت',
            5 => 'اكسسوارات وزينة',
            6 => 'زيوت وفلاتر',
            7 => 'كهرباء سيارات',
            8 => 'قطع بودي وهيكل',
            9 => 'قطع محركات',
            10 => 'خدمات تشليح',
        ];
        foreach ($businessTypes as $id => $name) {
            BusinessType::updateOrCreate(['id' => $id], ['name' => $name]);
        }

        $brands = [
            1 => 'Toyota',
            2 => 'Hyundai',
            3 => 'Mercedes-Benz',
            4 => 'Kia',
            5 => 'BMW',
            6 => 'Chevrolet',
            7 => 'Volkswagen',
            8 => 'Mazda',
            9 => 'Nissan',
            10 => 'Honda',
            11 => 'Ford',
            12 => 'Mitsubishi',
            13 => 'Suzuki',
            14 => 'Renault',
            15 => 'Peugeot',
            16 => 'Audi',
            17 => 'Lexus',
            18 => 'Jeep',
            19 => 'GMC',
            20 => 'Opel',
        ];
        foreach ($brands as $id => $name) {
            CarBrand::updateOrCreate(['id' => $id], ['name' => $name]);
        }

        $categories = [
            1 => 'فرامل وديسكات',
            2 => 'غماشة وركسين',
            3 => 'هيكل خارجي وصاج',
            4 => 'ميكانيك عام',
            5 => 'كهرباء ونظام إنارة',
            6 => 'فلتر وبواجي',
            7 => 'محرك وقطع داخلية',
            8 => 'قير وناقل حركة',
            9 => 'تبريد وراديتر',
            10 => 'تعليق ومساعدات',
            11 => 'إطارات وجنوط',
            12 => 'زيوت وسوائل',
            13 => 'بطاريات',
            14 => 'حساسات وكمبيوتر',
            15 => 'مرايا وزجاج',
            16 => 'أنظمة عادم',
            17 => 'داخلية واكسسوارات',
            18 => 'مصابيح وإنارة',
            19 => 'مكيف وتبريد',
            20 => 'أبواب وأقفال',
            21 => 'مقود وتعليق',
            22 => 'طرمبات وفلاتر',
            23 => 'سيور وبكرات',
        ];
        foreach ($categories as $id => $name) {
            PartCategory::updateOrCreate(['id' => $id], ['name' => $name]);
        }
    }
}
