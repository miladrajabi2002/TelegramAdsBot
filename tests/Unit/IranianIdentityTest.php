<?php

namespace Tests\Unit;

use App\Support\IranianIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IranianIdentityTest extends TestCase
{
    #[Test]
    public function it_normalizes_persian_and_arabic_digits(): void
    {
        $this->assertSame('0912', IranianIdentity::digits('۰۹۱٢'));
    }

    #[Test]
    public function it_validates_iranian_national_ids_without_accepting_repeated_digits(): void
    {
        $this->assertTrue(IranianIdentity::validNationalId('0013542648'));
        $this->assertFalse(IranianIdentity::validNationalId('0013542649'));
        $this->assertFalse(IranianIdentity::validNationalId('1111111111'));
    }

    #[Test]
    public function it_validates_bank_card_luhn_checksum(): void
    {
        $this->assertTrue(IranianIdentity::validCard('6037-9975-1234-5670'));
        $this->assertFalse(IranianIdentity::validCard('6037997512345678'));
    }
}
