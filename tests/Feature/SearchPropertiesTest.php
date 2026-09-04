<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Closure;
use Database\Factories\OfferFactory;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchPropertiesTest extends TestCase
{
    use RefreshDatabase;

    private const string CHECK_IN = '2026-10-10';

    private const string CHECK_OUT = '2026-10-15';

    private Supplier $supplierA;

    private Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
        $this->supplierA = Supplier::query()->where('slug', 'supplier-a')->sole();
        $this->supplierB = Supplier::query()->where('slug', 'supplier-b')->sole();
    }

    public function test_it_returns_each_property_with_its_cheapest_live_offer_in_the_shape_of_the_task(): void
    {
        $this->travelTo('2026-09-03 12:00:00');
        $property = Property::factory()->create([
            'code' => 'BCN-0001',
            'name' => 'Apartment near Sagrada Familia',
            'city' => 'Barcelona',
        ]);
        $this->liveOffer(['price' => 80000])->for($property)->create();
        $best = $this->liveOffer(['price' => 72500, 'available_units' => 2])->for($property)->create();

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // Two live offers on one property: a count over offers, not rank-1 rows, says 2.
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0', [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'City' => 'Barcelona',
                'best_offer' => [
                    'id' => $best->id,
                    'supplier' => 'supplier-a',
                    'price' => 72500,
                    'currency' => 'EUR',
                    'available_units' => 2,
                    'expires_at' => '2026-09-10T12:00:00Z',
                ],
            ]);
    }

    public function test_the_cheapest_offer_wins_across_suppliers_and_the_property_appears_once(): void
    {
        $property = Property::factory()->create();
        $this->liveOffer(['price' => 72500])->for($property)->create();
        $best = $this->liveOffer(['price' => 69000], $this->supplierB)->for($property)->create();

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $best->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
            ->assertJsonPath('data.0.best_offer.price', 69000);
    }

    public function test_between_equally_priced_offers_of_one_property_the_older_row_wins(): void
    {
        $property = Property::factory()->create();
        $older = $this->liveOffer(['price' => 72500])->for($property)->create();
        $this->liveOffer(['price' => 72500], $this->supplierB)->for($property)->create();

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $older->id);
    }

    /**
     * @param  Closure(OfferFactory): OfferFactory  $state
     */
    #[DataProvider('offersThatAreNotLive')]
    public function test_it_ignores_an_offer_that_is_not_live_for_the_search(Closure $state): void
    {
        $state($this->liveOffer())->create();

        $this->search()->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * @return array<string, array{Closure(OfferFactory): OfferFactory}>
     */
    public static function offersThatAreNotLive(): array
    {
        return [
            'other check_in' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['check_in' => '2026-10-11'])],
            'other check_out' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['check_out' => '2026-10-14'])],
            'too small for the guests' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['max_guests' => 1])],
            'sold out' => [fn (OfferFactory $factory): OfferFactory => $factory->soldOut(2)],
            'nothing published' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['available_units' => 0])],
            'expired' => [fn (OfferFactory $factory): OfferFactory => $factory->expired()],
            // The rule is strict: a second stored in the database is never greater than the
            // second of the request that follows it.
            'expires this very second' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['expires_at' => now()])],
        ];
    }

    /**
     * @param  Closure(OfferFactory): OfferFactory  $state
     */
    #[DataProvider('offersOnTheBoundary')]
    public function test_it_keeps_an_offer_that_sits_exactly_on_a_boundary(Closure $state): void
    {
        $this->freezeTime();
        $state($this->liveOffer())->create();

        $this->search()->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{Closure(OfferFactory): OfferFactory}>
     */
    public static function offersOnTheBoundary(): array
    {
        return [
            'fits the guests exactly' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['max_guests' => 2])],
            'one unit left' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['available_units' => 3, 'reserved_units' => 2])],
            'expires a second from now' => [fn (OfferFactory $factory): OfferFactory => $factory->state(['expires_at' => now()->addSecond()])],
        ];
    }

    public function test_guests_defaults_to_one(): void
    {
        $this->liveOffer(['max_guests' => 1])->create();

        $this->search(['guests' => null])->assertOk()->assertJsonCount(1, 'data');
        $this->search(['guests' => 2])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reserved_units_are_subtracted_from_the_published_availability(): void
    {
        $this->liveOffer(['available_units' => 3, 'reserved_units' => 1])->create();

        $this->search()->assertOk()->assertJsonPath('data.0.best_offer.available_units', 2);
    }

    public function test_a_sold_out_cheaper_offer_yields_to_the_next_live_one_of_the_property(): void
    {
        $property = Property::factory()->create();
        $this->liveOffer(['price' => 50000])->soldOut(2)->for($property)->create();
        $next = $this->liveOffer(['price' => 60000])->for($property)->create();

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $next->id)
            ->assertJsonPath('data.0.best_offer.price', 60000);
    }

    public function test_offers_of_the_property_for_other_dates_do_not_affect_its_best_price(): void
    {
        $property = Property::factory()->create();
        $this->liveOffer(['price' => 40000, 'check_out' => '2026-10-12'])->for($property)->create();
        $this->liveOffer(['price' => 90000])->for($property)->create();

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.price', 90000);
    }

    public function test_it_filters_by_city_regardless_of_case(): void
    {
        $this->liveOffer()->for(Property::factory()->state(['code' => 'BCN-0001', 'city' => 'Barcelona']))->create();
        $this->liveOffer()->for(Property::factory()->state(['code' => 'MAD-0001', 'city' => 'Madrid']))->create();

        $this->search(['city' => 'Barcelona'])->assertOk()->assertJsonPath('data.*.code', ['BCN-0001']);
        $this->search(['city' => 'barcelona'])->assertOk()->assertJsonPath('data.*.code', ['BCN-0001']);
        $this->search(['city' => 'Lisbon'])->assertOk()->assertJsonCount(0, 'data');
        $this->search()->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_results_are_ordered_by_price(): void
    {
        foreach ([300 => 'C', 100 => 'A', 200 => 'B'] as $price => $code) {
            $this->liveOffer(['price' => $price])->for(Property::factory()->state(['code' => $code]))->create();
        }

        $this->search()->assertOk()->assertJsonPath('data.*.code', ['A', 'B', 'C']);
    }

    public function test_pagination_neither_skips_nor_repeats_properties_when_prices_tie(): void
    {
        $codes = ['P-1', 'P-2', 'P-3', 'P-4', 'P-5'];
        foreach ($codes as $code) {
            $this->liveOffer(['price' => 50000])->for(Property::factory()->state(['code' => $code]))->create();
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, $this->search(['per_page' => 2, 'page' => $page])->assertOk()->json('data.*.code'));
        }

        sort($seen);
        $this->assertSame($codes, $seen);
    }

    public function test_the_envelope_carries_next_prev_and_per_page(): void
    {
        $this->liveOffer(['price' => 100])->for(Property::factory()->state(['code' => 'CHEAP']))->create();
        $this->liveOffer(['price' => 200])->for(Property::factory()->state(['code' => 'DEAR']))->create();

        $this->search()
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('links.prev', null);

        $first = $this->search(['per_page' => 1])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.*.code', ['CHEAP'])
            ->assertJsonPath('links.prev', null);

        // The links keep the search parameters, so they can actually be followed.
        $second = $this->getJson($first->json('links.next'))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.*.code', ['DEAR'])
            ->assertJsonPath('links.next', null);

        $this->getJson($second->json('links.prev'))
            ->assertOk()
            ->assertJsonPath('data.*.code', ['CHEAP']);
    }

    public function test_it_answers_an_empty_page_when_nothing_matches_or_the_page_is_past_the_end(): void
    {
        $this->search()
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('links.next', null);

        $this->liveOffer()->create();

        $this->search(['page' => 99])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('links.next', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $errors
     */
    #[DataProvider('invalidSearches')]
    public function test_it_rejects_an_invalid_search(array $overrides, array $errors): void
    {
        $this->search($overrides)->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidSearches(): array
    {
        return [
            'no dates' => [['check_in' => null, 'check_out' => null], ['check_in', 'check_out']],
            'check_out on check_in' => [['check_out' => self::CHECK_IN], ['check_out']],
            'check_in with a time part' => [['check_in' => '2026-10-10T00:00:00Z'], ['check_in']],
            'zero guests' => [['guests' => 0], ['guests']],
            'non-numeric guests' => [['guests' => 'two'], ['guests']],
            'zero per_page' => [['per_page' => 0], ['per_page']],
            'per_page beyond the cap' => [['per_page' => 101], ['per_page']],
            'city longer than the column' => [['city' => str_repeat('x', 256)], ['city']],
        ];
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_results(): void
    {
        $this->liveOffer()->create();
        $queriesForOne = $this->countQueries(fn () => $this->search()->assertOk()->assertJsonCount(1, 'data'));

        $this->liveOffer()->count(4)->create();
        $queriesForFive = $this->countQueries(fn () => $this->search()->assertOk()->assertJsonCount(5, 'data'));

        // Count, page, properties, suppliers: two for the paginator and two for eager loading.
        $this->assertSame(4, $queriesForOne);
        $this->assertSame($queriesForOne, $queriesForFive);
    }

    private function countQueries(Closure $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }

    /**
     * The search of the task description (guests: 2), with overrides; null drops a parameter.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function search(array $overrides = []): TestResponse
    {
        $parameters = array_filter(
            array_replace(['check_in' => self::CHECK_IN, 'check_out' => self::CHECK_OUT, 'guests' => 2], $overrides),
            fn (mixed $value): bool => $value !== null,
        );

        return $this->getJson(route('properties.index', $parameters));
    }

    /**
     * An offer the search of the task description finds; overrides make it miss.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function liveOffer(array $attributes = [], ?Supplier $supplier = null): OfferFactory
    {
        return Offer::factory()
            ->for($supplier ?? $this->supplierA)
            ->state(array_replace([
                'check_in' => self::CHECK_IN,
                'check_out' => self::CHECK_OUT,
                'max_guests' => 4,
                'price' => 72500,
                'currency' => 'EUR',
                'available_units' => 2,
            ], $attributes));
    }
}
