<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CustomerFeedback;
use App\Models\Floor;
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
use App\Support\Tenancy\TenantContext;
use App\Support\WhatsApp\MetaWhatsAppCloudApi;
use App\Support\WhatsApp\WhatsAppProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
