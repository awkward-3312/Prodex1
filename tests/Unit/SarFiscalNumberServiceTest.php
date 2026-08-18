<?php

namespace Tests\Unit;

use App\Exceptions\SarFiscalException;
use App\Services\SarFiscalNumberService;
use PHPUnit\Framework\TestCase;

class SarFiscalNumberServiceTest extends TestCase
{
    public function test_it_formats_the_honduras_sar_number(): void
    {
        $service = new SarFiscalNumberService;

        $this->assertSame(
            '000-001-01-00000001',
            $service->formatNumber('000', '001', '01', 1)
        );

        $this->assertSame(
            '999-999-01-99999999',
            $service->formatNumber('999', '999', '01', 99999999)
        );
    }

    /** @dataProvider invalidNumbers */
    public function test_it_rejects_invalid_codes_or_sequences(
        string $establishment,
        string $point,
        string $type,
        int $sequence
    ): void {
        $this->expectException(SarFiscalException::class);

        (new SarFiscalNumberService)->formatNumber(
            $establishment,
            $point,
            $type,
            $sequence
        );
    }

    public static function invalidNumbers(): array
    {
        return [
            ['1', '001', '01', 1],
            ['000', '1A1', '01', 1],
            ['000', '001', '1', 1],
            ['000', '001', '01', 0],
            ['000', '001', '01', 100000000],
        ];
    }
}
