<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Reference data and the owner account only. No seeder creates analyses, predictions or outcomes:
 * the development database never contains AI output the engine did not produce.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            ExchangeSeeder::class,
            StockSeeder::class,
            UserSeeder::class,
        ]);
    }
}
