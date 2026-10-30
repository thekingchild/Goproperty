<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Organizations that chose a plan in the signup wizard before P06-01b get
 * their `trialing` subscription on it, ending on their trial end (P06-01b;
 * docs/plans/P06-subtasks.md default 9). A trial already over is ended by
 * the daily `subscriptions-trials` job. Organizations with no plan yet get
 * theirs when they choose one. The down migration leaves the rows to the
 * table's own down migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::table('plans')->pluck('id', 'key');
        $now = now();

        DB::table('organizations')
            ->whereNotNull('signup_plan_key')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('subscriptions')->whereColumn('subscriptions.organization_id', 'organizations.id'))
            ->orderBy('id')
            ->chunk(200, function ($organizations) use ($plans, $now): void {
                $rows = [];

                foreach ($organizations as $organization) {
                    $planId = $plans[$organization->signup_plan_key] ?? null;

                    if ($planId === null) {
                        continue;
                    }

                    $rows[] = [
                        'organization_id' => $organization->id,
                        'plan_id' => $planId,
                        'billing_cycle' => 'monthly',
                        'status' => 'trialing',
                        'trial_ends_at' => $organization->trial_ends_at ?? $now,
                        'status_changed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('subscriptions')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // Nothing: the subscriptions table's own down migration drops the rows.
    }
};
