<?php

namespace Tests\Feature\Public;

use App\Actions\Feedback\SubmitPublicFeedbackAction;
use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\CloseTableAction;
use App\Exceptions\Public\FeedbackAlreadySubmittedException;
use App\Models\CustomerFeedback;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCustomerFeedback;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicFeedbackTest extends TestCase
{
    use InteractsWithCustomerFeedback, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * @param  array{0: mixed, 1: User, 2: Restaurant, 3: RestaurantProduct}|null  $tenant  Pass the result of createTenantWithRestaurantProduct() when $waiter must belong to the SAME restaurant as the session.
     * @return array{0: Table, 1: TableSession, 2: User, 3: Restaurant, 4: RestaurantProduct}
     */
    private function paidSession(?User $waiter = null, ?array $tenant = null): array
    {
        [, $owner, $restaurant, $rp] = $tenant ?? $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        if ($waiter) {
            app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        }

        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        return [$table, $session->refresh(), $owner, $restaurant, $rp];
    }

    // --- Token issuance & discovery -----------------------------------------
    //
    // Token-lifecycle fix: feedback_token is minted at session-open (see
    // OpenTableAction), not at payment — closing the race where a fast
    // cashier payment->close leaves no polling window for the client to
    // ever capture it. `eligible` alone (backend-authoritative, re-checked
    // by SubmitPublicFeedbackAction) gates whether the visit can actually
    // be reviewed.

    public function test_feedback_token_already_exists_the_moment_a_session_opens(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->assertNotNull($session->refresh()->feedback_token);
    }

    public function test_open_session_exposes_token_via_public_table_with_eligible_false(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['session' => [
                'active' => true,
                'feedback' => ['eligible' => false, 'token' => $session->refresh()->feedback_token],
            ]]]);
    }

    public function test_payment_reuses_the_same_token_minted_at_open_and_flips_eligible_true(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $tokenAtOpen = $session->refresh()->feedback_token;

        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $paid = $session->refresh();
        $this->assertSame($tokenAtOpen, $paid->feedback_token);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['session' => ['feedback' => ['eligible' => true, 'token' => $tokenAtOpen]]]]);
    }

    public function test_feedback_token_is_generated_when_session_becomes_paid(): void
    {
        // Historical name kept for the assertion it still makes: a paid
        // session always has a token (now inherited from open, not minted
        // here) — see test_payment_reuses_the_same_token_minted_at_open_and_flips_eligible_true
        // for the "same token" guarantee itself.
        [, $session] = $this->paidSession();

        $this->assertNotNull($session->feedback_token);
    }

    public function test_submit_before_payment_is_rejected_even_with_a_captured_token(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $tokenAtOpen = $session->refresh()->feedback_token;

        $response = $this->postJson("/api/v1/public/feedback/{$tokenAtOpen}", $this->defaultFeedbackPayload())
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_PAID_FOR_FEEDBACK']]);
        $this->assertDatabaseCount('customer_feedbacks', 0);
    }

    public function test_token_captured_before_payment_still_submits_after_payment_and_immediate_close(): void
    {
        // The exact race this fix closes: no polling happens between
        // payment and close, so the client's only chance is the token it
        // captured while the session was still open+unpaid.
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $tokenCapturedBeforePayment = $session->refresh()->feedback_token;

        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);
        app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $response = $this->postJson("/api/v1/public/feedback/{$tokenCapturedBeforePayment}", $this->defaultFeedbackPayload())
            ->assertStatus(201);

        $response->assertJson(['data' => ['overall_rating' => $this->defaultFeedbackPayload()['overall_rating']]]);
        $this->assertDatabaseCount('customer_feedbacks', 1);
    }

    public function test_reopening_the_same_table_mints_a_different_token_never_revealing_the_old_one(): void
    {
        [$table, $oldSession, $owner] = $this->paidSession();
        app(CloseTableAction::class)->execute($oldSession, $owner);

        $newSession = $this->openSession($table, $owner);

        $this->assertNotSame($oldSession->feedback_token, $newSession->refresh()->feedback_token);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();
        $response->assertJson(['data' => ['session' => ['active' => true, 'feedback' => ['eligible' => false, 'token' => $newSession->feedback_token]]]]);
        $this->assertStringNotContainsString($oldSession->feedback_token, json_encode($response->json()));
    }

    public function test_old_sessions_token_stays_isolated_and_operable_after_a_new_session_opens(): void
    {
        [$table, $oldSession, $owner] = $this->paidSession();
        app(CloseTableAction::class)->execute($oldSession, $owner);
        $this->openSession($table, $owner);

        // The old token is a closed chapter, not reachable via the table
        // endpoint anymore, but it still resolves to its OWN visit (not
        // the new one) through the dedicated feedback endpoints.
        $context = $this->getJson("/api/v1/public/feedback/{$oldSession->feedback_token}")->assertOk();
        $this->assertSame($table->name, $context->json('data.table.name'));

        $this->postJson("/api/v1/public/feedback/{$oldSession->feedback_token}", $this->defaultFeedbackPayload())
            ->assertStatus(201);

        $feedback = CustomerFeedback::query()->firstOrFail();
        $this->assertSame($oldSession->id, $feedback->table_session_id);
    }

    public function test_public_table_resolution_exposes_feedback_token_once_paid_and_still_open(): void
    {
        [$table, $session] = $this->paidSession();

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();

        $response->assertJson([
            'data' => [
                'session' => [
                    'active' => true,
                    'feedback' => [
                        'eligible' => true,
                        'token' => $session->feedback_token,
                        'already_submitted' => false,
                    ],
                ],
            ],
        ]);
    }

    public function test_public_menu_also_exposes_feedback_token_once_paid(): void
    {
        [$table, $session, , $restaurant] = $this->paidSession();
        $this->createMenu($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertOk()
            ->assertJson(['data' => ['session' => ['feedback' => ['eligible' => true, 'token' => $session->feedback_token]]]]);
    }

    public function test_unpaid_active_session_reports_feedback_not_eligible(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['session' => ['feedback' => ['eligible' => false]]]]);
    }

    public function test_feedback_token_is_no_longer_exposed_once_table_is_closed_and_reused(): void
    {
        [$table, $session, $owner] = $this->paidSession();
        app(CloseTableAction::class)->execute($session, $owner);

        // The physical QR is reused by a new guest — the table endpoint
        // must not leak the previous visit's feedback token to them.
        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();

        $response->assertJson(['data' => ['session' => ['active' => false, 'feedback' => ['eligible' => false]]]]);
        $response->assertJsonMissingPath('data.session.feedback.token');
    }

    // --- Context endpoint --------------------------------------------------

    public function test_context_endpoint_returns_restaurant_and_table_display_info(): void
    {
        [$table, $session, , $restaurant] = $this->paidSession();

        $this->getJson("/api/v1/public/feedback/{$session->feedback_token}")
            ->assertOk()
            ->assertJson([
                'data' => [
                    'already_submitted' => false,
                    'restaurant' => ['name' => $restaurant->name],
                    'table' => ['name' => $table->name],
                ],
            ]);
    }

    public function test_context_endpoint_never_exposes_internal_ids(): void
    {
        [, $session] = $this->paidSession();

        $response = $this->getJson("/api/v1/public/feedback/{$session->feedback_token}")->assertOk();

        $response->assertJsonMissingPath('data.table_session_id');
        $response->assertJsonMissingPath('data.restaurant.id');
        $response->assertJsonMissingPath('data.table.id');
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->getJson('/api/v1/public/feedback/not-a-real-token')
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'FEEDBACK_TOKEN_NOT_FOUND']]);
    }

    // --- Submission ----------------------------------------------------------

    public function test_submit_records_feedback_and_returns_201(): void
    {
        [, $session] = $this->paidSession();

        $response = $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())
            ->assertStatus(201);

        $response->assertJson(['data' => ['first_name' => 'Ana', 'overall_rating' => 5]]);
        $this->assertDatabaseCount('customer_feedbacks', 1);
    }

    public function test_submit_attributes_the_officially_assigned_waiter(): void
    {
        $tenant = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($tenant[0], $tenant[2], 'waiter', 'w1');
        [, $session] = $this->paidSession($waiter, $tenant);

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        $feedback = CustomerFeedback::query()->firstOrFail();
        $this->assertSame($waiter->id, $feedback->waiter_id);
    }

    public function test_submit_without_assigned_waiter_leaves_waiter_id_null_and_still_succeeds(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        $feedback = CustomerFeedback::query()->firstOrFail();
        $this->assertNull($feedback->waiter_id);
    }

    public function test_later_waiter_reassignment_does_not_change_already_submitted_feedback(): void
    {
        $tenant = $this->createTenantWithRestaurantProduct();
        [$organization, $owner, $restaurant] = $tenant;
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'w2');
        [, $session] = $this->paidSession($waiterA, $tenant);

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        app(AssignWaiterAction::class)->execute($session->refresh(), $waiterB, $owner);

        $feedback = CustomerFeedback::query()->firstOrFail();
        $this->assertSame($waiterA->id, $feedback->waiter_id);
    }

    public function test_submit_does_not_touch_bill_payment_or_session_state(): void
    {
        [, $session] = $this->paidSession();
        $before = $session->replicate();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        $session->refresh();
        $this->assertSame($before->status, $session->status);
        $this->assertSame($before->payment_status, $session->payment_status);
        $this->assertDatabaseCount('payment_records', 1); // only the original payment, none added
    }

    public function test_duplicate_submission_with_identical_payload_replays_200(): void
    {
        [, $session] = $this->paidSession();
        $payload = $this->defaultFeedbackPayload();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $payload)->assertStatus(201);
        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $payload)->assertStatus(200);

        $this->assertDatabaseCount('customer_feedbacks', 1);
    }

    public function test_duplicate_submission_with_different_payload_returns_409(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        $different = array_merge($this->defaultFeedbackPayload(), ['overall_rating' => 1]);
        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $different)
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'FEEDBACK_ALREADY_SUBMITTED']]);

        $this->assertDatabaseCount('customer_feedbacks', 1);
    }

    public function test_already_submitted_flag_reflects_in_context_after_submission(): void
    {
        [, $session] = $this->paidSession();
        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        $this->getJson("/api/v1/public/feedback/{$session->feedback_token}")
            ->assertOk()
            ->assertJson(['data' => ['already_submitted' => true]]);
    }

    public function test_invalid_token_submission_returns_404(): void
    {
        $this->postJson('/api/v1/public/feedback/not-a-real-token', $this->defaultFeedbackPayload())
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'FEEDBACK_TOKEN_NOT_FOUND']]);
    }

    public function test_feedback_still_submittable_after_the_session_is_closed(): void
    {
        [, $session, $owner] = $this->paidSession();
        $closed = app(CloseTableAction::class)->execute($session, $owner);

        $this->postJson("/api/v1/public/feedback/{$closed->feedback_token}", $this->defaultFeedbackPayload())
            ->assertStatus(201);
    }

    // --- Validation ----------------------------------------------------------

    public function test_rating_of_0_is_rejected(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", array_merge($this->defaultFeedbackPayload(), ['overall_rating' => 0]))
            ->assertStatus(422);
    }

    public function test_rating_of_6_is_rejected(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", array_merge($this->defaultFeedbackPayload(), ['food_rating' => 6]))
            ->assertStatus(422);
    }

    public function test_decimal_rating_is_rejected(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", array_merge($this->defaultFeedbackPayload(), ['service_rating' => 3.5]))
            ->assertStatus(422);
    }

    public function test_empty_first_name_is_rejected(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", array_merge($this->defaultFeedbackPayload(), ['first_name' => '']))
            ->assertStatus(422);
    }

    public function test_empty_last_name_is_rejected(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", array_merge($this->defaultFeedbackPayload(), ['last_name' => '']))
            ->assertStatus(422);
    }

    public function test_oversized_comment_is_rejected(): void
    {
        [, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", array_merge(
            $this->defaultFeedbackPayload(),
            ['experience_comment' => str_repeat('a', 1001)],
        ))->assertStatus(422);
    }

    public function test_submission_without_optional_fields_still_succeeds(): void
    {
        [, $session] = $this->paidSession();
        $payload = $this->defaultFeedbackPayload();
        unset($payload['experience_comment'], $payload['improvement_comment'], $payload['contact']);

        $this->postJson("/api/v1/public/feedback/{$session->feedback_token}", $payload)->assertStatus(201);
    }

    // --- RestaurantScope / cross-restaurant isolation ----------------------

    public function test_a_restaurants_feedback_token_cannot_be_confused_with_another_restaurants(): void
    {
        [, $sessionA] = $this->paidSession();
        [, $sessionB] = $this->paidSession();

        $this->assertNotSame($sessionA->feedback_token, $sessionB->feedback_token);

        $this->postJson("/api/v1/public/feedback/{$sessionA->feedback_token}", $this->defaultFeedbackPayload())->assertStatus(201);

        // sessionB's token still resolves to sessionB's own (empty) context.
        $this->getJson("/api/v1/public/feedback/{$sessionB->feedback_token}")
            ->assertOk()
            ->assertJson(['data' => ['already_submitted' => false]]);
    }

    // --- Concurrency -----------------------------------------------------

    public function test_two_concurrent_submissions_for_the_same_session_only_one_creates_a_row(): void
    {
        [, $session] = $this->paidSession();

        $first = app(SubmitPublicFeedbackAction::class)->execute($session->feedback_token, $this->defaultFeedbackPayload());
        $this->assertFalse($first['replayed']);

        // A second, concurrent-style call with a DIFFERENT payload must be
        // rejected rather than silently creating a second row — the
        // unique constraint on customer_feedbacks.table_session_id is the
        // real guard here, not merely the "does one already exist" check
        // (see SubmitPublicFeedbackAction docblock).
        $this->expectException(FeedbackAlreadySubmittedException::class);

        try {
            app(SubmitPublicFeedbackAction::class)->execute(
                $session->feedback_token,
                array_merge($this->defaultFeedbackPayload(), ['overall_rating' => 1]),
            );
        } finally {
            $this->assertSame(1, CustomerFeedback::query()->where('table_session_id', $session->id)->count());
        }
    }
}
