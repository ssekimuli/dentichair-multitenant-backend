<?php

namespace Tests\Unit;

use App\Services\Import\ClinicLedgerNormalizer;
use PHPUnit\Framework\TestCase;

class ClinicLedgerNormalizerTest extends TestCase
{
    private function writeCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ledger_test_');
        file_put_contents($path, $content);
        return $path;
    }

    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    public function test_date_header_applies_to_patients_below_it_until_the_next_header(): void
    {
        $input = implode("\n", [
            'DATE,PT NO.,PATIENT NAME,SEX,AGE,ADRESS,PROCEDURE,TOTAL AMOUNT,AMOUNT PAID,TELEPHONE NUMBER,ARRIVAL TIME,NEXT APPOINTMENT,DOCTOR',
            '3rd/01/2026,,,,,,,,,,,,',
            ',1.0,JOAN ATUHIIRWE,F,37.0,KISASI,EXT,45000.0,45000.0,0786272265[J.A],,ANYTIME,F.K',
            ',2.0,GLORIA ACHAN,F,15.0,NABWERU,BRACES,2500000.0,500000.0,0781452352[DAD],,2ND/02/2026,F.K',
            '4th/01/2026,,,,,,,,,,,,',
            ',3.0,MUTABAAZI ABDULRAHMAN,M,14.0,MAKANDWA,PAYMENT,400000.0,200000.0,0779360996[MUM],10am,46056.0,F.K',
        ]);

        $input_path = $this->writeCsv($input);
        $output_path = (new ClinicLedgerNormalizer())->normalize($input_path, ',');

        $this->assertNotNull($output_path);
        $rows = $this->readRows($output_path);

        $this->assertSame(
            ['last_visit', 'first_name', 'phone', 'gender', 'age', 'address', 'history', 'next_visit'],
            $rows[0]
        );
        $this->assertSame(['2026-01-03', 'JOAN ATUHIIRWE', '0786272265[J.A]', 'Female', '37', 'KISASI', 'EXT', ''], $rows[1]);
        $this->assertSame(['2026-01-03', 'GLORIA ACHAN', '0781452352[DAD]', 'Female', '15', 'NABWERU', 'BRACES', '2026-02-02'], $rows[2]);
        $this->assertSame(['2026-01-04', 'MUTABAAZI ABDULRAHMAN', '0779360996[MUM]', 'Male', '14', 'MAKANDWA', 'PAYMENT', '2026-02-03'], $rows[3]);

        @unlink($input_path);
        @unlink($output_path);
    }

    public function test_a_plain_patient_list_is_not_treated_as_a_ledger(): void
    {
        $input_path = $this->writeCsv("first_name,phone\nJane,0700000000\n");

        $this->assertNull((new ClinicLedgerNormalizer())->normalize($input_path, ','));

        @unlink($input_path);
    }

    public function test_invoice_target_builds_one_invoice_per_visit_with_status(): void
    {
        $input = implode("\n", [
            'DATE,PT NO.,PATIENT NAME,SEX,AGE,ADRESS,PROCEDURE,TOTAL AMOUNT,AMOUNT PAID,TELEPHONE NUMBER,ARRIVAL TIME,NEXT APPOINTMENT,DOCTOR',
            '3rd/01/2026,,,,,,,,,,,,',
            ',1.0,JOAN ATUHIIRWE,F,37.0,KISASI,EXT,45000.0,45000.0,0786272265[J.A],,ANYTIME,F.K',
            ',2.0,GLORIA ACHAN,F,15.0,NABWERU,BRACES,2500000.0,500000.0,0781452352[DAD],,2ND/02/2026,F.K',
            ',12.0,LUBOWA ALVINE DALIMA,M,16.0,KAWEMPE,BRACES REVIEW,80K@Visit,80000.0,0709654668(L.A.D),,3RD/02/2026,F.K',
            ',15.0,NIN APOSTLE,F,ADULT,NUMARK,EXT BOF 37,200USD,,0705827504(N.A),,,F.K',
        ]);

        $input_path = $this->writeCsv($input);
        $output_path = (new ClinicLedgerNormalizer())->normalize($input_path, ',', 'invoices');

        $this->assertNotNull($output_path);
        $rows = $this->readRows($output_path);

        $this->assertSame(
            ['receipt_number', 'issue_date', 'patient_name', 'contact', 'procedure', 'amount', 'paid_amount', 'status'],
            $rows[0]
        );
        $this->assertSame(
            ['LEDGER-2026-01-03-PT1', '2026-01-03', 'JOAN ATUHIIRWE', '0786272265[J.A]', 'EXT', '45000', '45000', 'Paid'],
            $rows[1]
        );
        $this->assertSame(
            ['LEDGER-2026-01-03-PT2', '2026-01-03', 'GLORIA ACHAN', '0781452352[DAD]', 'BRACES', '2500000', '500000', 'Partially Paid'],
            $rows[2]
        );
        $this->assertSame(
            ['LEDGER-2026-01-03-PT12', '2026-01-03', 'LUBOWA ALVINE DALIMA', '0709654668(L.A.D)', 'BRACES REVIEW', '80000', '80000', 'Paid'],
            $rows[3]
        );
        // Unusable currency: amount left blank, status Pending
        $this->assertSame(
            ['LEDGER-2026-01-03-PT15', '2026-01-03', 'NIN APOSTLE', '0705827504(N.A)', 'EXT BOF 37', '', '', 'Pending'],
            $rows[4]
        );

        @unlink($input_path);
        @unlink($output_path);
    }

    public function test_parses_money_cells(): void
    {
        $normalizer = new ClinicLedgerNormalizer();

        $this->assertSame(2500000.0, $normalizer->parseMoney('2500000.0'));
        $this->assertSame(80000.0, $normalizer->parseMoney('80K@Visit'));
        $this->assertSame(20000.0, $normalizer->parseMoney('20k@visist'));
        $this->assertSame(1000.0, $normalizer->parseMoney('1,000'));
        $this->assertNull($normalizer->parseMoney('200USD'));
        $this->assertNull($normalizer->parseMoney(''));
    }

    public function test_parses_ordinal_and_serial_dates(): void
    {
        $normalizer = new ClinicLedgerNormalizer();

        $this->assertSame('2026-01-03', $normalizer->parseLedgerDate('3rd/01/2026'));
        $this->assertSame('2026-06-12', $normalizer->parseLedgerDate('12th/06/2026'));
        $this->assertSame('2026-01-23', $normalizer->parseLedgerDate('23th/01/2026'));
        $this->assertSame('2026-02-03', $normalizer->parseLedgerDate('46056.0'));
        $this->assertSame('2026-02-03', $normalizer->parseLedgerDate('2026-02-03 00:00:00'));
        $this->assertNull($normalizer->parseLedgerDate('ANYTIME'));
        $this->assertNull($normalizer->parseLedgerDate(''));
    }
}
