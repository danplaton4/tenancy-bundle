<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Health\HealthResponseSanitizer;

final class HealthResponseSanitizerTest extends TestCase
{
    private HealthResponseSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HealthResponseSanitizer();
    }

    // sanitize() — single string redaction

    public function testRedactsMysqlDsnPassword(): void
    {
        $input = 'Connection failed: mysql://app:s3cr3t@db.host/tenant_a';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString('s3cr3t', $output);
        $this->assertStringContainsString('***', $output);
    }

    public function testRedactsPostgresDsnPassword(): void
    {
        $input = 'postgres://u:pw@h:5432/db';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString(':pw@', $output);
        $this->assertStringContainsString('***', $output);
    }

    public function testRedactsRedisDsnPassword(): void
    {
        $input = 'redis://u:pw@h';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString(':pw@', $output);
        $this->assertStringContainsString('***', $output);
    }

    public function testRedactsSmtpDsnPassword(): void
    {
        $input = 'smtp://u:pw@h';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString(':pw@', $output);
        $this->assertStringContainsString('***', $output);
    }

    public function testPassthroughForNonDsnText(): void
    {
        $input = 'no dsn here just text';
        $output = $this->sanitizer->sanitize($input);

        $this->assertSame($input, $output);
    }

    public function testRetainsTextAroundRedactedDsn(): void
    {
        $input = 'Connection failed: mysql://app:s3cr3t@db.host/tenant_a';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('Connection failed:', $output);
        $this->assertStringContainsString('mysql://', $output);
        $this->assertStringContainsString('@db.host/tenant_a', $output);
    }

    // sanitizeArray() — recursive array walk

    public function testSanitizesNestedStringValues(): void
    {
        $data = [
            'a' => 'mysql://u:pw@h',
            'nested' => [
                'b' => 'redis://u:pw@h',
                'n' => 5,
            ],
        ];

        $result = $this->sanitizer->sanitizeArray($data);

        $this->assertStringNotContainsString(':pw@', $result['a']);
        $this->assertStringContainsString('***', $result['a']);
        $this->assertStringNotContainsString(':pw@', $result['nested']['b']);
        $this->assertStringContainsString('***', $result['nested']['b']);
    }

    public function testLeavesIntegerValuesUntouched(): void
    {
        $data = [
            'nested' => [
                'b' => 'redis://u:pw@h',
                'n' => 5,
            ],
        ];

        $result = $this->sanitizer->sanitizeArray($data);

        $this->assertSame(5, $result['nested']['n']);
    }

    public function testSanitizesTopLevelStringValues(): void
    {
        $data = ['dsn' => 'mysql://user:secret@host/db'];

        $result = $this->sanitizer->sanitizeArray($data);

        $this->assertStringNotContainsString('secret', $result['dsn']);
        $this->assertStringContainsString('***', $result['dsn']);
    }

    public function testLeavesNonDsnStringValuesUntouched(): void
    {
        $data = ['message' => 'just a plain string', 'count' => 42];

        $result = $this->sanitizer->sanitizeArray($data);

        $this->assertSame('just a plain string', $result['message']);
        $this->assertSame(42, $result['count']);
    }

    public function testEmptyArrayReturnsEmpty(): void
    {
        $this->assertSame([], $this->sanitizer->sanitizeArray([]));
    }
}
