<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\UsageLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:adjust-usage {user : User id or email} {minutes : Positive or negative minute adjustment} {note : Required support note} {--created-by=support : Support actor label}')]
#[Description('Apply an append-only support adjustment to a user billing minute ledger.')]
class AdjustBillingUsage extends Command
{
    public function handle(UsageLedger $ledger): int
    {
        $user = $this->user((string) $this->argument('user'));

        if (! $user instanceof User) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $minutes = (int) $this->argument('minutes');
        $note = trim((string) $this->argument('note'));
        $createdBy = trim((string) $this->option('created-by'));

        if ($minutes === 0 || $note === '' || $createdBy === '') {
            $this->error('Minutes, note, and created-by are required.');

            return self::FAILURE;
        }

        $event = $ledger->adjust($user, $minutes, $note, $createdBy);

        $this->info("Recorded {$minutes} minute adjustment for {$user->email} as event {$event->id}.");

        return self::SUCCESS;
    }

    private function user(string $identifier): ?User
    {
        return User::query()
            ->when(
                ctype_digit($identifier),
                fn ($query) => $query->whereKey((int) $identifier),
                fn ($query) => $query->where('email', $identifier),
            )
            ->first();
    }
}
