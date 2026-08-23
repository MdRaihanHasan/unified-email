<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * What is actually going on with each mailbox.
 *
 * Every failure mode in this design is quiet — a queued job with no worker, an
 * expired cursor, a revoked token — and the UI can only hint at them. This prints
 * the state a person would otherwise have to guess at.
 */
class MailStatusCommand extends Command
{
    protected $signature = 'mail:status';

    protected $description = 'Show the sync state of every connected mailbox';

    public function handle(): int
    {
        $accounts = MailAccount::query()->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            $this->components->warn('No mailboxes connected. Settings → Connect a Gmail account.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($accounts as $account) {
            $this->account($account);
        }

        $this->queue();

        return self::SUCCESS;
    }

    private function account(MailAccount $account): void
    {
        $progress = $account->importProgress();

        $this->line("  <options=bold>{$account->email}</> <fg=gray>({$account->label} · {$account->provider->value})</>");
        $this->line('    status          '.$this->status($account));
        $this->line('    folders         '.$account->folders()->count().' known, '
            ."{$progress['folders_done']}/{$progress['folders_total']} walked");
        $this->line('    messages        '.$progress['messages']);
        $this->line('    threads         '.$account->messages()->distinct()->count('thread_id'));
        $this->line('    first import    '.($account->hasFinishedBackfill()
            ? '<fg=green>done '.$account->backfill_done_at->diffForHumans().'</>'
            : ($account->hasStartedImport() ? '<fg=yellow>in progress</>' : '<fg=red>not started</>')));
        $this->line('    last synced     '.($account->last_synced_at?->diffForHumans() ?? '<fg=red>never</>'));
        $this->line('    sync cursor     '.($account->sync_cursor ? json_encode($account->sync_cursor) : '<fg=gray>none yet</>'));

        if (filled($account->last_error)) {
            $this->line('    last error      <fg=red>'.$account->last_error.'</>');
        }

        // The one diagnosis worth spelling out, because nothing else reveals it.
        if ($account->importStalled()) {
            $this->newLine();
            $this->line('    <fg=yellow>The first import has not started.</> The job is queued but nothing');
            $this->line('    is running it. Check the worker:  <options=bold>docker compose ps worker</>');
            $this->line('    and its output:                   <options=bold>docker compose logs --tail=50 worker</>');
        }

        $this->newLine();
    }

    private function status(MailAccount $account): string
    {
        return match ($account->status->value) {
            'active' => '<fg=green>active</>',
            'connecting' => '<fg=yellow>connecting</>',
            'auth_error' => '<fg=red>auth error — reconnect needed</>',
            'disabled' => '<fg=gray>disabled</>',
            default => $account->status->value,
        };
    }

    private function queue(): void
    {
        $this->line('  <options=bold>Queue</>');

        try {
            $pending = Queue::size();
        } catch (\Throwable $e) {
            $this->line('    <fg=red>unreachable: '.$e->getMessage().'</>');
            $this->newLine();

            return;
        }

        $this->line('    pending jobs    '.$pending);

        // Only present on the database driver, and only worth reporting when it is.
        try {
            $failed = DB::table('failed_jobs')->count();
            $this->line('    failed jobs     '.($failed > 0 ? "<fg=red>{$failed}</>" : '0')
                .($failed > 0 ? '  <fg=gray>php artisan queue:failed</>' : ''));
        } catch (\Throwable) {
            // No failed_jobs table configured; nothing to report.
        }

        if ($pending > 0) {
            $this->newLine();
            $this->line('    <fg=gray>Jobs waiting. If this number never falls, the worker is not running.</>');
        }

        $this->newLine();
    }
}
