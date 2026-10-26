<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CustomerFeedback;
use App\Models\Floor;
use App\Models\Invitation;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\StaffShift;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Models\WaiterCall;
use App\Models\Zone;
use App\Notifications\InvitationNotification;
use App\Policies\AuditLogPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CustomerFeedbackPolicy;
use App\Policies\FloorPolicy;
use App\Policies\MenuPolicy;
use App\Policies\ModifierGroupPolicy;
use App\Policies\ModifierOptionPolicy;
use App\Policies\OrderPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RestaurantPolicy;
use App\Policies\RestaurantProductPolicy;
use App\Policies\StaffPolicy;
use App\Policies\StaffShiftPolicy;
use App\Policies\TablePolicy;
use App\Policies\TableRequestPolicy;
use App\Policies\TableSessionPolicy;
use App\Policies\WaiterCallPolicy;
use App\Policies\ZonePolicy;
use App\Support\Invitations\InvitationTokens;
use App\Support\Tenancy\TenantContext;
use App\Support\WhatsApp\MetaWhatsAppCloudApi;
use App\Support\WhatsApp\WhatsAppProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        // CARTA 9.1E: the single WhatsApp boundary — the Meta Cloud API.
        $this->app->bind(WhatsAppProvider::class, MetaWhatsAppCloudApi::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Invitation delivery state: "sent" means the mail transport
        // accepted the message for THIS (latest) send — never that it
        // reached the inbox. Failures are recorded by
        // InvitationNotification::failed().
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->notification instanceof InvitationNotification && $event->channel === 'mail') {
                Invitation::query()
                    ->whereKey($event->notification->invitationId)
                    ->where('delivery_sequence', $event->notification->sequence)
                    ->update(['delivery_status' => Invitation::DELIVERY_SENT, 'last_sent_at' => now(), 'delivery_failed_at' => null]);
            }
        });

        // Invitation preview/accept are reachable without a session: IP
        // bucket against enumeration, token bucket against hammering one
        // link (the hash, never the token, is the key).
        RateLimiter::for('invitation-public', function (Request $request) {
            $token = $request->input('token');

            return [
                Limit::perMinute(30)->by('invitation|'.$request->ip()),
                Limit::perMinute(10)->by('invitation-token|'.InvitationTokens::hash(is_string($token) ? $token : '')),
            ];
        });

        // Accepting an existing account checks its password: same shape as
        // the login limiter (per token and per IP).
        RateLimiter::for('invitation-accept', function (Request $request) {
            $token = $request->input('token');

            return [
                Limit::perMinute(10)->by('invitation-accept|'.$request->ip()),
                Limit::perMinute(5)->by('invitation-accept-token|'.InvitationTokens::hash(is_string($token) ? $token : '')),
            ];
        });

        // Password recovery request: per IP and per e-mail (hash of the
        // normalized address, never the address itself as cache key). The
        // broker adds its own 60 s per-account throttle; the response is
        // identical either way (no enumeration through 429 vs 200 on the
        // per-account rule — only these request-level buckets answer 429).
        RateLimiter::for('password-forgot', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? strtolower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('pw-forgot|'.$request->ip()),
                Limit::perHour(5)->by('pw-forgot-email|'.hash('sha256', $email)),
            ];
        });

        // Reset preview/confirmation: guessing tokens is bounded per IP and
        // per target e-mail.
        RateLimiter::for('password-reset', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? strtolower(trim($email)) : '';

            return [
                Limit::perMinute(10)->by('pw-reset|'.$request->ip()),
                Limit::perMinute(10)->by('pw-reset-email|'.hash('sha256', $email)),
            ];
        });

        // Platform onboarding and invitation (re)sends: deliberate actions,
        // each one may send an e-mail.
        RateLimiter::for('invitation-writes', function (Request $request) {
            return Limit::perMinute(20)->by('user:'.($request->user()?->id ?? $request->ip()));
        });

        // CARTA 9.1E: manual WhatsApp resend of a Cierre Diario — a few per
        // close and user per minute is plenty for a deliberate action and
        // stops accidental/abusive bursts (each one is a real WhatsApp
        // message to a person).
        RateLimiter::for('day-close-whatsapp-resend', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id.'|'.$request->route('dayClose'));
        });

        // Meta webhook callback: generous (Meta batches and retries), but
        // bounded per source IP. Signature verification is the real gate.
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $input = $request->input('email');
            $email = is_string($input) ? strtolower(trim($input)) : '';

            // The email+IP bucket stops a single source; the email-only bucket
            // (any IP) stops a distributed guess against one account, which
            // rotating IPs would otherwise multiply by 5/min per address.
            // Trade-off: an attacker can lock a known email out for up to an
            // hour — accepted over unbounded password guessing.
            return array_values(array_filter([
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                $email !== '' ? Limit::perHour(30)->by('email:'.$email) : null,
            ]));
        });

        // Every authenticated route (tenant, platform, /auth/me|context|
        // logout, /broadcasting/auth). Keyed by user — the throttle runs
        // after auth:sanctum, so the user is always resolved — with the IP
        // only as a defensive fallback. Generous enough for a busy SPA
        // (dashboards fan out several reads at once), but bounds scripted
        // abuse from a single account.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by('user:'.($request->user()?->id ?? $request->ip()));
        });

        // Expensive endpoints on top of `api`: analytics aggregation, the
        // Cierre Diario preview/PDF render and product media uploads (up to
        // 50 MB each). Separate bucket so a burst here never eats the
        // user's general `api` allowance, and vice versa.
        RateLimiter::for('api-heavy', function (Request $request) {
            return Limit::perMinute(20)->by('user:'.($request->user()?->id ?? $request->ip()));
        });

        // Staff create/update: users.email is unique platform-wide, so a 422
        // on a taken email inevitably reveals that the address exists in
        // SOME organization. Removing that signal needs an invite flow;
        // until then this bounds how fast a manager can probe addresses.
        RateLimiter::for('staff-writes', function (Request $request) {
            return Limit::perMinute(20)->by('user:'.($request->user()?->id ?? $request->ip()));
        });

        // /sanctum/csrf-cookie starts a session (a `sessions` row with the
        // database driver) for every cookieless caller. Per IP, generous
        // enough for many staff devices behind one restaurant NAT.
        RateLimiter::for('csrf-cookie', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('public-menu', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Keyed by IP + publicToken (not IP alone) so many customers on the
        // same restaurant Wi-Fi don't share one bucket and lock each other
        // out of ordering.
        RateLimiter::for('public-entry', function (Request $request) {
            return [
                Limit::perMinute(10)->by('entry|'.$request->ip().'|'.$request->route('publicToken')),
                Limit::perMinute(60)->by('entry|'.$request->ip()),
            ];
        });

        RateLimiter::for('public-orders', function (Request $request) {
            $token = (string) $request->route('publicToken');

            return Limit::perMinute(10)->by($request->ip().'|'.$token);
        });

        RateLimiter::for('public-table-requests', function (Request $request) {
            $token = (string) $request->route('publicToken');

            return Limit::perMinute(10)->by($request->ip().'|'.$token);
        });

        // Keyed by IP + feedbackToken (not IP alone), same reasoning as
        // public-orders/public-table-requests above.
        RateLimiter::for('public-feedback', function (Request $request) {
            $token = (string) $request->route('feedbackToken');

            return Limit::perMinute(10)->by($request->ip().'|'.$token);
        });

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Restaurant::class, RestaurantPolicy::class);
        Gate::policy(User::class, StaffPolicy::class);
        Gate::policy(Table::class, TablePolicy::class);
        Gate::policy(TableSession::class, TableSessionPolicy::class);
        Gate::policy(Menu::class, MenuPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(RestaurantProduct::class, RestaurantProductPolicy::class);
        Gate::policy(ModifierGroup::class, ModifierGroupPolicy::class);
        Gate::policy(ModifierOption::class, ModifierOptionPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(TableRequest::class, TableRequestPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Floor::class, FloorPolicy::class);
        Gate::policy(Zone::class, ZonePolicy::class);
        Gate::policy(StaffShift::class, StaffShiftPolicy::class);
        Gate::policy(WaiterCall::class, WaiterCallPolicy::class);
        Gate::policy(CustomerFeedback::class, CustomerFeedbackPolicy::class);
    }
}
