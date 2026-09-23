<?php

namespace Tests\Feature;

use App\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The search filters offers with the `bookable` scope, the booking checks them with
 * `isExpired()` and `isSoldOut()`: both forms of the rule must agree on every state.
 */
class OfferBookableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  callable(): array<string, mixed>  $state
     */
    #[DataProvider('states')]
    public function test_the_scope_and_the_methods_agree(callable $state, bool $isBookable): void
    {
        $this->freezeSecond();
        $offer = Offer::factory()->create($state());

        $this->assertSame($isBookable, Offer::query()->bookable()->whereKey($offer)->exists(), 'scope');
        $this->assertSame($isBookable, ! $offer->isExpired() && ! $offer->isSoldOut(), 'methods');
    }

    /**
     * @return array<string, array{callable(): array<string, mixed>, bool}>
     */
    public static function states(): array
    {
        return [
            'live' => [fn (): array => [], true],
            'the last unit left' => [fn (): array => ['available_units' => 2, 'reserved_units' => 1], true],
            'expiring next second' => [fn (): array => ['expires_at' => now()->addSecond()], true],
            'expiring this very second' => [fn (): array => ['expires_at' => now()], false],
            'expired' => [fn (): array => ['expires_at' => now()->subMinute()], false],
            'sold out' => [fn (): array => ['available_units' => 2, 'reserved_units' => 2], false],
            'supply lowered under its reservations' => [fn (): array => ['available_units' => 1, 'reserved_units' => 3], false],
            'published with no units' => [fn (): array => ['available_units' => 0], false],
        ];
    }
}
