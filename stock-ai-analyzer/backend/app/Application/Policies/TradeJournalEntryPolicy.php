<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Journal\Models\TradeJournalEntry;
use Illuminate\Auth\Access\Response;

/**
 * A journal is private. Someone else's id answers 404, not 403, so ids can't be probed.
 */
final class TradeJournalEntryPolicy
{
    public function view(User $user, TradeJournalEntry $trade): Response
    {
        return $this->owner($user, $trade);
    }

    public function update(User $user, TradeJournalEntry $trade): Response
    {
        return $this->owner($user, $trade);
    }

    public function delete(User $user, TradeJournalEntry $trade): Response
    {
        return $this->owner($user, $trade);
    }

    private function owner(User $user, TradeJournalEntry $trade): Response
    {
        return $trade->user()->is($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
