<?php

namespace App\Services;

use App\Exceptions\RefusedException;
use App\Models\AdminAction;
use App\Models\Competition;
use App\Models\PostalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Sprouthub\Verity\Engine\VerityException;
use Sprouthub\Verity\Facades\Verity;

/**
 * The free postal route: one envelope, one entry, with the same chance as a paid ticket. The same
 * play check applies, and the per-person limit counts postal and paid entries together (Verity
 * enforces it per owner). Every envelope is recorded, entered or refused.
 */
final class Postal
{
    public function __construct(private PlayCheck $check, private Fulfilment $fulfilment) {}

    public function log(Competition $c, string $email, Carbon $receivedAt, User $admin): PostalEntry
    {
        $user = User::query()->where('email', strtolower(trim($email)))->whereNotNull('email_verified_at')->first();
        $refuse = fn (string $why) => $this->record($c, $user, $receivedAt, $admin, 'refused', $why);

        if (! $c->verity_ref) {
            return $refuse('The competition isn’t published.');
        }
        if ($receivedAt->gt($c->closes_at)) {
            return $refuse('Received after the closing time.');
        }
        if ($receivedAt->isFuture()) {
            return $refuse('The received time is in the future.');
        }
        if (! $user) {
            return $refuse('No account with a confirmed email address matches.');
        }
        try {
            $this->check->assertAllowed($user);
        } catch (RefusedException $e) {
            return $refuse($e->getMessage());
        }

        return DB::transaction(function () use ($c, $user, $receivedAt, $admin, $refuse) {
            try {
                $group = Verity::addPostalEntry($c->verity_ref, $user->ownerRef(), ['ordered_at' => $receivedAt]);
            } catch (VerityException $e) {
                return $refuse($e->getMessage());
            }
            if ($c->isInstant()) {
                $this->fulfilment->playAll($group->reference);
            }

            $entry = $this->record($c, $user, $receivedAt, $admin, 'entered', null, $group->reference);
            $tickets = \Sprouthub\Verity\Models\Entry::query()->where('entry_group_id', $group->id)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
            DB::afterCommit(function () use ($user, $c, $tickets) {
                try {
                    $user->notify(new \App\Notifications\PostalEntryEntered($c, $tickets));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Postal entry email not sent', ['user' => $user->id]);
                }
            });

            return $entry;
        });
    }

    /**
     * A batch of envelopes from a CSV (SOW 2.7): email and received_at (UK time) per row. Each row goes through
     * log() exactly like a hand-logged envelope, so every one is recorded, entered or refused.
     *
     * @return array{entered: int, refused: int, rows: list<array{line: int, email: string, status: string, reason: ?string}>}
     */
    public function importCsv(Competition $c, string $csv, User $admin): array
    {
        $rows = \App\Support\Csv::rows($csv, 2000);
        if ($rows && ! array_key_exists('email', $rows[0])) {
            throw new \InvalidArgumentException('The file needs an "email" column (and "received_at").');
        }
        $out = ['entered' => 0, 'refused' => 0, 'rows' => []];
        foreach ($rows as $i => $r) {
            $email = $r['email'] ?? '';
            $at = self::ukTime($r['received_at'] ?? '');
            if ($email === '' || ! $at || trim($r['received_at'] ?? '') === '') {
                $out['refused']++;
                $out['rows'][] = ['line' => $i + 2, 'email' => $email, 'status' => 'skipped', 'reason' => 'Missing email or received time.'];

                continue;
            }
            $p = $this->log($c, $email, $at, $admin);
            $out[$p->status === 'entered' ? 'entered' : 'refused']++;
            $out['rows'][] = ['line' => $i + 2, 'email' => $email, 'status' => $p->status, 'reason' => $p->reason];
        }

        return $out;
    }

    /** "21/11/2026 14:30", "21/11/2026", "2026-11-21 14:30" or ISO, read as UK time (day first, never US order). */
    public static function ukTime(string $s): ?Carbon
    {
        $s = trim($s);
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $s, new \DateTimeZone('Europe/London'));
            $errors = \DateTime::getLastErrors();
            if ($d && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return Carbon::instance($d)->utc();
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $s)) {
            try {
                return Carbon::parse($s)->utc();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function record(Competition $c, ?User $user, Carbon $at, User $admin, string $status, ?string $reason, ?string $group = null): PostalEntry
    {
        $p = PostalEntry::query()->create([
            'competition_id' => $c->id, 'user_id' => $user?->id, 'received_at' => $at, 'status' => $status,
            'reason' => $reason, 'entry_group_ref' => $group, 'logged_by' => $admin->id,
        ]);
        AdminAction::record($admin, 'postal.'.$status, $c->id, $reason, ['user' => $user?->id]);

        return $p;
    }
}
