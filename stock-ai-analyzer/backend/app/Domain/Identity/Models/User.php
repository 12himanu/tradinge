<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Market\Models\Exchange;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $timezone
 * @property array<string, mixed>|null $settings
 * @property int|null $default_exchange_id
 * @property-read Exchange|null $defaultExchange
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'settings', 'default_exchange_id'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<Exchange, $this> */
    public function defaultExchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class, 'default_exchange_id');
    }

    /** @return HasMany<ChartUpload, $this> */
    public function chartUploads(): HasMany
    {
        return $this->hasMany(ChartUpload::class);
    }

    /** @return HasMany<Analysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    /** @return HasMany<TradeJournalEntry, $this> */
    public function tradeJournalEntries(): HasMany
    {
        return $this->hasMany(TradeJournalEntry::class);
    }
}
