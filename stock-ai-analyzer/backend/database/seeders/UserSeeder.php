<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Exchange;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates (or updates) the local owner account from OWNER_* environment values.
 * Never falls back to a default password.
 */
class UserSeeder extends Seeder
{
    private const int MIN_PASSWORD_LENGTH = 12;

    public function run(): void
    {
        $email = config('stock_ai.owner.email');
        $password = config('stock_ai.owner.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command->warn('OWNER_EMAIL / OWNER_PASSWORD not set — owner account not created.');

            return;
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->command->warn('OWNER_PASSWORD must be at least 12 characters — owner account not created.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => Str::lower($email)],
            [
                'name' => (string) config('stock_ai.owner.name', 'Owner'),
                'password' => $password,
                'timezone' => 'Asia/Kathmandu',
                'default_exchange_id' => Exchange::query()->where('code', 'NEPSE')->value('id'),
                'settings' => config('stock_ai.default_user_settings'),
            ],
        );
    }
}
