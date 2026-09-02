<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\MenuPolicy;
use App\Policies\ModifierGroupPolicy;
use App\Policies\ModifierOptionPolicy;
use App\Policies\OrderPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RestaurantPolicy;
use App\Policies\RestaurantProductPolicy;
use App\Policies\StaffPolicy;
use App\Policies\TablePolicy;
use App\Policies\TableSessionPolicy;
use App\Support\Tenancy\TenantContext;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
