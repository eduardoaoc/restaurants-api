<?php

namespace Tests\Feature\RestaurantSettings;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use App\Support\Restaurants\GoogleReviewUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * settings.google_review_url (CARTA 5.3A): GET/PATCH contract,
 * normalization, the HTTPS-Google-only allowlist (GoogleReviewUrl),
 * permission/scope and audit.
 */
class GoogleReviewUrlSettingsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const VALID_URL = 'https://g.page/r/CabcdEFGhij123XYZ/review';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function settingsUrl(Restaurant $restaurant): string
    {
        return "/api/v1/restaurants/{$restaurant->id}/settings";
    }

    private function storedUrl(Restaurant $restaurant): ?string
    {
        return RestaurantSettings::query()->where('restaurant_id', $restaurant->id)->value('google_review_url');
    }

    // --- A–D. Contract / persistence / normalization ------------------------------

    public function test_a_unconfigured_restaurant_returns_null(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson($this->settingsUrl($restaurant))
            ->assertOk()
            ->assertJsonPath('data.settings.google_review_url', null);
    }

    public function test_b_owner_saves_a_valid_url(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])
            ->assertOk()
            ->assertJsonPath('data.settings.google_review_url', self::VALID_URL);

        $this->assertSame(self::VALID_URL, $this->storedUrl($restaurant));

        $this->actingAs($owner, 'web')
            ->getJson($this->settingsUrl($restaurant))
            ->assertJsonPath('data.settings.google_review_url', self::VALID_URL);
    }

    public function test_c_owner_replaces_the_url(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $new = 'https://search.google.com/local/writereview?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4';

        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])->assertOk();
        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurant), ['google_review_url' => $new])->assertOk();

        $this->assertSame($new, $this->storedUrl($restaurant));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function clearingValues(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'null' => [null],
        ];
    }

    #[DataProvider('clearingValues')]
    public function test_d_blank_or_null_clears_the_url(?string $value): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])->assertOk();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => $value])
            ->assertOk()
            ->assertJsonPath('data.settings.google_review_url', null);

        $this->assertNull($this->storedUrl($restaurant));
    }

    public function test_surrounding_whitespace_is_trimmed_and_nothing_else_is_rewritten(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $url = 'https://www.google.com/maps/place/Casa+Pepe/@39.47,-0.37,17z/data=!4m8?entry=ttu&g_ep=EgoyMDI2#reviews';

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => "  {$url}\n"])
            ->assertOk();

        $this->assertSame($url, $this->storedUrl($restaurant));
    }

    public function test_omitting_the_field_keeps_the_stored_url(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])->assertOk();

        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurant), ['waiter_call_enabled' => false])->assertOk();

        $this->assertSame(self::VALID_URL, $this->storedUrl($restaurant));
    }

    // --- E–J. Rejected ------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function rejectedValues(): array
    {
        return [
            'e. http' => ['http://g.page/r/CabcdEFGhij123XYZ/review'],
            'f. javascript' => ['javascript:alert(1)'],
            'f. javascript with google text' => ['javascript://g.page/%0aalert(1)'],
            'g. data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file' => ['file:///etc/passwd'],
            'relative' => ['/r/CabcdEFGhij123XYZ/review'],
            'scheme-relative' => ['//g.page/r/CabcdEFGhij123XYZ/review'],
            'h. external host' => ['https://example.com/review'],
            'i. google.com as a subdomain of another host' => ['https://google.com.evil.example/review'],
            'j. lookalike host' => ['https://evilgoogle.com/review'],
            'lookalike g.page' => ['https://evilg.page/r/abc/review'],
            'retired generic goo.gl' => ['https://goo.gl/maps/abc123'],
            'google redirector' => ['https://www.google.com/url?q=https://evil.example'],
            'maps.google.com redirector' => ['https://maps.google.com/url?q=https://evil.example'],
            'search.google.com outside /local' => ['https://search.google.com/search?q=casa+pepe'],
            'www.google.com outside /maps' => ['https://www.google.com/search?q=casa+pepe'],
            'mapsfoo path prefix trick' => ['https://www.google.com/mapsevil'],
            'bare g.page' => ['https://g.page/'],
            'userinfo' => ['https://g.page@evil.example/r/abc/review'],
            'userinfo with google-looking user' => ['https://evil.example\\@g.page/r/abc/review'],
            'explicit port' => ['https://g.page:8443/r/abc/review'],
            'trailing-dot host' => ['https://g.page./r/abc/review'],
            'internal whitespace' => ['https://g.page/r/abc def/review'],
            'embedded newline' => ["https://g.page/r/abc\nSet-Cookie:x/review"],
            'control character' => ["https://g.page/r/abc\x00/review"],
            'uppercase http' => ['HTTP://g.page/r/example/review'],
            'mixed-case http' => ['HtTp://g.page/r/example/review'],
            'uppercase javascript' => ['JavaScript:alert(1)'],
            'uppercase data' => ['DATA:text/html,<script>alert(1)</script>'],
            'uppercase file' => ['FILE:///etc/passwd'],
            'uppercase scheme with fake host' => ['HTTPS://google.com.evil.example/review'],
            'uppercase scheme with redirector' => ['HTTPS://www.google.com/url?q=https://evil.example'],
            'malformed' => ['https:///g.page'],
            'not a url' => ['casa pepe google'],
            'too long' => ['https://g.page/r/'.str_repeat('a', GoogleReviewUrl::MAX_LENGTH)],
            'not a string' => [['https://g.page/r/abc/review']],
            'integer' => [12345],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_invalid_urls_are_rejected_and_not_persisted(mixed $value): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('google_review_url');

        $this->assertNull($this->storedUrl($restaurant));
        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->count());
    }

    // --- K. Accepted --------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedValues(): array
    {
        return [
            'g.page review link' => ['https://g.page/r/CabcdEFGhij123XYZ/review'],
            'g.page example review link' => ['https://g.page/r/example/review'],
            'g.page profile link' => ['https://g.page/r/CabcdEFGhij123XYZ'],
            'writereview by place id' => ['https://search.google.com/local/writereview?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4'],
            'reviews list by place id' => ['https://search.google.com/local/reviews?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4'],
            'maps share short link' => ['https://maps.app.goo.gl/AbCdEfGh12345'],
            'www.google.com maps place' => ['https://www.google.com/maps/place/?q=place_id:ChIJN1t_tDeuEmsRUsoyG83frY4'],
            'google.com maps' => ['https://google.com/maps/search/?api=1&query=Casa+Pepe&query_place_id=ChIJN1t_tDeuEmsRUsoyG83frY4'],
            'maps.google.com cid' => ['https://maps.google.com/?cid=1234567890123456789'],
            'maps.google.com maps path' => ['https://maps.google.com/maps?cid=1234567890123456789'],
            'uppercase host' => ['https://G.PAGE/r/CabcdEFGhij123XYZ/review'],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_k_legitimate_google_links_are_accepted_verbatim(string $url): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => $url])
            ->assertOk()
            ->assertJsonPath('data.settings.google_review_url', $url);

        $this->assertSame($url, $this->storedUrl($restaurant));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function schemeCaseVariants(): array
    {
        return [
            'uppercase HTTPS' => ['HTTPS://g.page/r/example/review', 'https://g.page/r/example/review'],
            'mixed-case HtTpS' => [
                'HtTpS://search.google.com/local/writereview?placeid=example',
                'https://search.google.com/local/writereview?placeid=example',
            ],
            'only the scheme is rewritten' => [
                'HTTPS://G.PAGE/r/AbC/Review?X=Y#Frag',
                'https://G.PAGE/r/AbC/Review?X=Y#Frag',
            ],
        ];
    }

    #[DataProvider('schemeCaseVariants')]
    public function test_scheme_is_case_insensitive_and_stored_lowercase(string $input, string $stored): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => "  {$input} "])
            ->assertOk()
            ->assertJsonPath('data.settings.google_review_url', $stored);

        $this->assertSame($stored, $this->storedUrl($restaurant));
    }

    // --- L–M. Permission / scope -----------------------------------------------------------

    public function test_l_user_without_permission_is_forbidden_and_nothing_changes(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])
            ->assertForbidden();

        $this->assertNull($this->storedUrl($restaurant));
    }

    public function test_m_manager_of_a_sibling_restaurant_cannot_change_it(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->patchJson($this->settingsUrl($restaurantB), ['google_review_url' => self::VALID_URL])
            ->assertNotFound();

        $this->assertNull($this->storedUrl($restaurantB));
    }

    public function test_each_restaurant_keeps_its_own_url(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $urlB = 'https://maps.app.goo.gl/BranchB12345';

        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurantA), ['google_review_url' => self::VALID_URL])->assertOk();
        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurantB), ['google_review_url' => $urlB])->assertOk();

        $this->assertSame(self::VALID_URL, $this->storedUrl($restaurantA));
        $this->assertSame($urlB, $this->storedUrl($restaurantB));
    }

    public function test_manager_scoped_to_own_restaurant_can_set_it(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])
            ->assertOk();

        $this->assertSame(self::VALID_URL, $this->storedUrl($restaurant));
    }

    // --- N. Audit ---------------------------------------------------------------------------

    public function test_n_changes_go_through_the_existing_settings_audit(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])
            ->assertOk();
        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => ''])
            ->assertOk();

        $logs = AuditLog::query()
            ->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertSame($organization->id, $logs[0]->organization_id);
        $this->assertSame($restaurant->id, $logs[0]->restaurant_id);
        $this->assertSame($owner->id, $logs[0]->actor_user_id);
        $this->assertEquals(['google_review_url' => ['old' => null, 'new' => self::VALID_URL]], $logs[0]->changes);
        $this->assertEquals(['google_review_url' => ['old' => self::VALID_URL, 'new' => null]], $logs[1]->changes);
    }

    public function test_resaving_the_same_url_records_no_audit_event(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->actingAs($owner, 'web')->patchJson($this->settingsUrl($restaurant), ['google_review_url' => self::VALID_URL])->assertOk();

        $this->actingAs($owner, 'web')
            ->patchJson($this->settingsUrl($restaurant), ['google_review_url' => ' '.self::VALID_URL.' '])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED)->count());
    }
}
