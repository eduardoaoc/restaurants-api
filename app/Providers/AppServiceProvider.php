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
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
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
