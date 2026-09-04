<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Remove an away. Its windows go with it (the foreign key cascades), and the
 * scope is offered again from that moment on.
 *
 * It undoes nothing: appointments cancelled, reassigned or moved while the away
 * stood were settled by somebody and stay settled. There is nothing to refuse
 * either — an away holds no claims, so deleting one can strand nobody.
 */
final class DeleteUnavailability
{
    public function __invoke(Unavailability $away): void
    {
        DB::transaction(function () use ($away): void {
            $locked = Dibs::lock($away);

            if (! $locked instanceof Unavailability) {
                return;
            }

            $locked->delete();
        });
    }
}
