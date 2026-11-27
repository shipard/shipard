<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\AuthPolicy;
use Shipard\Core\Auth\PinPolicy;

/**
 * Politika PINu `auth.pin` (#177 U7, U21): výchozí hodnoty, meze a neplatná
 * sekce jako výchozí hodnoty s nálezem — nikdy výjimka.
 */
class PinPolicyTest extends TestCase
{
    private const array DEFAULTS = ['enabled' => false, 'minLength' => 4, 'maxLength' => 8, 'maxAttempts' => 5];

    public function testMissingSectionMeansDefaults(): void
    {
        $policy = PinPolicy::fromConfig(null);

        $this->assertSame(self::DEFAULTS, $policy->toArray());
        $this->assertSame(PinPolicy::SOURCE_DEFAULT, $policy->source);
        $this->assertTrue($policy->isValid());
        $this->assertNull($policy->problem);
    }

    public function testValidSectionIsUsed(): void
    {
        $policy = PinPolicy::fromConfig(['enabled' => true, 'minLength' => 5, 'maxLength' => 6, 'maxAttempts' => 3]);

        $this->assertSame(['enabled' => true, 'minLength' => 5, 'maxLength' => 6, 'maxAttempts' => 3], $policy->toArray());
        $this->assertSame(PinPolicy::SOURCE_CONFIGURED, $policy->source);
    }

    public function testMissingKeysFallBackToTheirDefaults(): void
    {
        $policy = PinPolicy::fromConfig(['enabled' => true]);

        $this->assertSame(['enabled' => true] + self::DEFAULTS, $policy->toArray());
        $this->assertTrue($policy->isValid());

        $this->assertSame(self::DEFAULTS, PinPolicy::fromConfig([])->toArray());
    }

    public function testBoundaryValuesAreValid(): void
    {
        $this->assertTrue(PinPolicy::fromConfig(['minLength' => 4, 'maxLength' => 4, 'maxAttempts' => 1])->isValid());
        $this->assertTrue(PinPolicy::fromConfig(['minLength' => 8, 'maxLength' => 8])->isValid());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidSections(): iterable
    {
        yield 'not an object' => ['on', "'auth.pin' must be an object"];
        yield 'list' => [[true, 4], "'auth.pin' must be an object"];
        yield 'unknown key' => [['enabled' => true, 'length' => 6], "'auth.pin.length' is not a known key"];
        yield 'enabled as number' => [['enabled' => 1], "'auth.pin.enabled' must be true or false"];
        yield 'length as text' => [['minLength' => '4'], "'auth.pin.minLength' must be a whole number"];
        yield 'min too small' => [['enabled' => true, 'minLength' => 3], "'auth.pin.minLength' must be at least 4"];
        yield 'max too big' => [['enabled' => true, 'maxLength' => 9], "'auth.pin.maxLength' must be at most 8"];
        yield 'min above max' => [['minLength' => 7, 'maxLength' => 6], "'auth.pin.minLength' must not be greater than 'auth.pin.maxLength'"];
        yield 'no attempts' => [['enabled' => true, 'maxAttempts' => 0], "'auth.pin.maxAttempts' must be at least 1"];
    }

    #[DataProvider('invalidSections')]
    public function testInvalidSectionMeansDefaultsWithAProblem(mixed $section, string $problem): void
    {
        $policy = PinPolicy::fromConfig($section);

        // Celé výchozí hodnoty — i `enabled: true` z neplatné sekce PIN nezapne.
        $this->assertSame(self::DEFAULTS, $policy->toArray());
        $this->assertSame(PinPolicy::SOURCE_INVALID, $policy->source);
        $this->assertFalse($policy->isValid());
        $this->assertSame($problem, $policy->problem);
    }

    public function testAuthPolicyCarriesThePinPolicy(): void
    {
        $this->assertSame(self::DEFAULTS, AuthPolicy::fromArray([])->pin->toArray());
        $this->assertSame(self::DEFAULTS, (new AuthPolicy())->pin->toArray());

        $policy = AuthPolicy::fromArray(['pin' => ['enabled' => true, 'maxAttempts' => 3]]);
        $this->assertTrue($policy->pin->enabled);
        $this->assertSame(3, $policy->pin->maxAttempts);
    }

    public function testInvalidPinSectionDoesNotBreakTheAuthPolicy(): void
    {
        $policy = AuthPolicy::fromArray(['local' => true, 'pin' => ['minLength' => 2]]);

        $this->assertTrue($policy->localLogin);
        $this->assertFalse($policy->pin->isValid());
        $this->assertFalse($policy->pin->enabled);
    }
}
