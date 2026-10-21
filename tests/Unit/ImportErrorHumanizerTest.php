<?php

namespace Tests\Unit;

use App\Services\Import\ImportErrorHumanizer;
use Tests\TestCase;

class ImportErrorHumanizerTest extends TestCase
{
    protected ImportErrorHumanizer $humanizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->humanizer = app(ImportErrorHumanizer::class);
    }

    /** @test */
    public function it_humanizes_duplicate_email_database_error_for_non_technical_user()
    {
        $rawError = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane.smith.sample@example.com' for key 'patients.patients_email_unique' (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: stagingapidb, SQL: insert into `patients` (`first_name`, `email`) values ('Jane', 'jane.smith.sample@example.com'))";

        $result = $this->humanizer->humanize(
            $rawError,
            'patients',
            ['first_name' => 'Jane', 'email' => 'jane.smith.sample@example.com'],
            ['email' => 'EMAIL_ADDRESS', 'first_name' => 'FIRST_NAME']
        );

        $this->assertEquals('DUPLICATE_RECORD', $result['error_code']);
        $this->assertEquals('email', $result['destination_field']);
        $this->assertEquals('EMAIL_ADDRESS', $result['source_column']);

        // Must NOT contain technical jargon
        $this->assertStringNotContainsString('SQLSTATE', $result['error_message']);
        $this->assertStringNotContainsString('1062', $result['error_message']);
        $this->assertStringNotContainsString('stagingapidb', $result['error_message']);
        $this->assertStringNotContainsString('127.0.0.1', $result['error_message']);
        $this->assertStringNotContainsString('insert into', $result['error_message']);

        // Must contain clear human explanation
        $this->assertStringContainsString('jane.smith.sample@example.com', $result['error_message']);
        $this->assertStringContainsString('patient', $result['error_message']);
        $this->assertStringContainsString('already exists in the system', $result['error_message']);
        $this->assertStringContainsString('Update Existing', $result['error_message']);
    }

    /** @test */
    public function it_humanizes_foreign_key_missing_reference_error()
    {
        $rawError = "SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`stagingapidb`.`patients`, CONSTRAINT `patients_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)) (SQL: insert into `patients` (`branch_id`) values (999))";

        $result = $this->humanizer->humanize(
            $rawError,
            'patients',
            ['first_name' => 'John', 'branch_id' => 999],
            ['branch_id' => 'BRANCH_CODE']
        );

        $this->assertEquals('INVALID_REFERENCE', $result['error_code']);
        $this->assertEquals('branch_id', $result['destination_field']);
        $this->assertEquals('BRANCH_CODE', $result['source_column']);
        $this->assertStringNotContainsString('SQLSTATE', $result['error_message']);
        $this->assertStringContainsString('does not exist in the system', $result['error_message']);
    }

    /** @test */
    public function it_humanizes_not_null_column_error()
    {
        $rawError = "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'first_name' cannot be null (SQL: insert into `patients` (`first_name`) values (null))";

        $result = $this->humanizer->humanize(
            $rawError,
            'patients',
            ['first_name' => null],
            ['first_name' => 'FIRST_NAME']
        );

        $this->assertEquals('REQUIRED_FIELD_EMPTY', $result['error_code']);
        $this->assertEquals('first_name', $result['destination_field']);
        $this->assertEquals('FIRST_NAME', $result['source_column']);
        $this->assertStringNotContainsString('SQLSTATE', $result['error_message']);
        $this->assertStringContainsString('is required and cannot be blank', $result['error_message']);
    }

    /** @test */
    public function it_humanizes_data_too_long_error()
    {
        $rawError = "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'phone' at row 1";

        $result = $this->humanizer->humanize(
            $rawError,
            'patients',
            ['phone' => '+2567000000000000000000000000000000'],
            ['phone' => 'PHONE_NUMBER']
        );

        $this->assertEquals('VALUE_TOO_LONG', $result['error_code']);
        $this->assertEquals('phone', $result['destination_field']);
        $this->assertEquals('PHONE_NUMBER', $result['source_column']);
        $this->assertStringContainsString('exceeds the maximum allowed', $result['error_message']);
    }

    /** @test */
    public function it_humanizes_invalid_date_format_error()
    {
        $rawError = "SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: 'not-a-date' for column 'date_of_birth' at row 1";

        $result = $this->humanizer->humanize(
            $rawError,
            'patients',
            ['date_of_birth' => 'not-a-date'],
            ['date_of_birth' => 'DOB']
        );

        $this->assertEquals('INVALID_FORMAT', $result['error_code']);
        $this->assertEquals('date_of_birth', $result['destination_field']);
        $this->assertEquals('DOB', $result['source_column']);
        $this->assertStringContainsString('is invalid. Please format it as YYYY-MM-DD', $result['error_message']);
    }

    /** @test */
    public function it_cleans_summary_for_atomic_and_job_failures()
    {
        $rawError = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane.smith.sample@example.com' for key 'patients.patients_email_unique' (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: stagingapidb, SQL: insert into `patients`...)";

        $summary = $this->humanizer->humanizeSummary($rawError);

        $this->assertStringNotContainsString('SQLSTATE', $summary);
        $this->assertStringNotContainsString('127.0.0.1', $summary);
        $this->assertStringNotContainsString('SQL:', $summary);
        $this->assertStringContainsString('Duplicate record found', $summary);
        $this->assertStringContainsString('jane.smith.sample@example.com', $summary);
    }
}
