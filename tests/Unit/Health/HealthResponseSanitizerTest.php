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

    // CR-01 regression: password-only DSN (no username), e.g. Redis AUTH.
    public function testRedactsPasswordOnlyDsn(): void
    {
        $input = 'Connection failed: redis://:s3cr3tpw@cache.host:6379';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString('s3cr3tpw', $output);
        $this->assertStringContainsString('***', $output);
        $this->assertStringContainsString('@cache.host:6379', $output);
    }

    // CR-01 regression: password containing a slash (valid for MySQL/Postgres).
    public function testRedactsPasswordContainingSlash(): void
    {
        $input = 'mysql://user:pa/ss@db.host/tenant';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString('pa/ss', $output);
        $this->assertStringContainsString('***', $output);
        $this->assertStringContainsString('@db.host/tenant', $output);
    }

    // Composite failover DSN must still redact both credentials after the widening.
    public function testRedactsBothCredentialsInFailoverDsn(): void
    {
        $input = 'failover(smtp://u:p1@h1 smtp://u:p2@h2)';
        $output = $this->sanitizer->sanitize($input);

        $this->assertStringNotContainsString(':p1@', $output);
        $this->assertStringNotContainsString(':p2@', $output);
        $this->assertSame(2, substr_count($output, '***'));
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
