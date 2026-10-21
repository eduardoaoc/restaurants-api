<?php

namespace App\Actions\Public;

use App\Actions\Tables\EnsureSessionWaiterAction;
use App\Actions\Tables\OpenTableAction;
use App\Exceptions\Billing\TableSessionAlreadyPaidException;
use App\Exceptions\Public\PublicTableNotFoundException;
use App\Models\Restaurant;
use App\Models\Table;
use App\Support\Restaurants\RestaurantOperationalLock;
use App\Support\Tables\WaiterAssignmentLock;
use Illuminate\Support\Facades\DB;

class EnterPublicTableAction
{
    public function __construct(
        private readonly ResolvePublicTableAction $resolveTable,
        private readonly OpenTableAction $openTable,
    ) {}

    public function execute(string $publicToken, ?string $visitToken): Table
    {
        $resolved = $this->resolveTable->execute($publicToken, $visitToken);

        return DB::transaction(function () use ($resolved, $publicToken, $visitToken) {
            RestaurantOperationalLock::shared($resolved->restaurant_id);
            WaiterAssignmentLock::acquire($resolved->restaurant_id);
            // Revalidate availability and token rotation under locks before
            // creating anything. Always take the restaurant lock first.
            $restaurant = Restaurant::query()->whereKey($resolved->restaurant_id)->sharedLock()->first();
            // Revalidate the scanned QR even when its pinned visit moved.
            $table = Table::query()->where('public_token', $publicToken)
                ->where('restaurant_id', $resolved->restaurant_id)->lockForUpdate()->first();
            if (! $restaurant || $restaurant->status !== 'active' || ! $table
                || $table->status !== 'active' || $table->public_token !== $publicToken) {
                throw new PublicTableNotFoundException;
            }

            $table = $this->resolveTable->execute($publicToken, $visitToken);
            // A previous visit can be resumed/read, never used as an entry
            // into a newer session. Only an explicit fresh QR entry opens.
            $session = $visitToken !== null
                ? $table->activeSession
                : $this->openTable->execute($table, null, 0, reuseActive: true);
            if ($visitToken === null && $session->isPaid()) {
                // A newly arriving guest cannot inherit the paid group's
                // feedback/visit credential while staff finishes closing.
                throw new TableSessionAlreadyPaidException;
            }
            $session = app(EnsureSessionWaiterAction::class)->execute($session);
            $session->ensureFeedbackToken();

            return $table->setRelation('activeSession', $session);
        });
    }
}
