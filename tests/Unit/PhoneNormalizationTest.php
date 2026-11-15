<?php

namespace Tests\Unit;

use App\Services\Import\ImportValidationService;
use Tests\TestCase;

class PhoneNormalizationTest extends TestCase
{
    /** @test */
    public function it_keeps_only_the_first_local_phone_number_in_a_cell()
    {
        $service = app(ImportValidationService::class);

        $cases = [
            '0701862015(D.A)' => '0701862015',
            '0701862015 (D.A)' => '0701862015',
            '0701862015 / 0750123456' => '0701862015',
            '0701862015, 0750123456' => '0701862015',
            '0701862015 2' => '0701862015',
            '0701862015ext2' => '0701862015',
            'Tel: 0701-862-015' => '0701862015',
            'D.A 0701862015' => '0701862015',
            '0701862015 [home]' => '0701862015',
            '+256 701 862 015' => '0701862015',
            '256701862015' => '0701862015',
            '701862015' => '0701862015',
            '700111222.0' => '0700111222',
            '(0700) 111 999' => '0700111999',
            '+44 20 7946 0000' => '+442079460000',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $service->normalizePhoneNumber($input), "Input: {$input}");
        }
    }

    /** @test */
    public function it_returns_null_when_there_is_no_phone_number()
    {
        $service = app(ImportValidationService::class);

        $this->assertNull($service->normalizePhoneNumber('N/A'));
        $this->assertNull($service->normalizePhoneNumber('unknown'));
        $this->assertNull($service->normalizePhoneNumber(''));
    }
}
