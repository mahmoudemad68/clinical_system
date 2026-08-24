<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Infrastructure\Telemetry\PatternRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Canary suite for the redaction processor (Phase 00 §5.3, gate G-05-03).
 *
 * Invariant 18: logs and traces contain identifiers and safe metadata, never
 * raw medical content, credentials, national IDs, prescription text, lab
 * contents, or unrestricted prompts and responses.
 *
 * Every canary below is synthetic. The national IDs are structurally valid but
 * deliberately impossible (future or nonsense birth dates), the phone numbers
 * are in reserved ranges, and no value here corresponds to a real person. That
 * matters: a test fixture that happens to be a real identifier is itself the
 * leak the suite exists to prevent.
 *
 * The suite asserts two directions, and the second is the one that catches a
 * lazy implementation: sensitive values disappear, AND ordinary operational
 * values survive. A redactor that replaces everything passes the first half
 * and makes logs useless.
 */
final class RedactionCanaryTest extends TestCase
{
    private PatternRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new PatternRedactor();
    }

    // ------------------------------------------------------------ canaries

    /**
     * Each case names the rule that must fire, not merely "something fired".
     *
     * This matters more than it looks. An earlier version of this suite only
     * asserted that the canary disappeared, and it kept passing with the
     * national-ID rule deleted: the `card_number` rule (13-19 digits) happened
     * to swallow a 14-digit national ID. Nothing leaked, but the national-ID
     * rule was untested, and narrowing `card_number` later — to Luhn-valid
     * numbers, say — would have started leaking national IDs silently.
     *
     * Asserting the emitted hint pins each rule independently.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function sensitiveValues(): array
    {
        return [
            // 14 digits, Egyptian national ID shape. Birth date 2999-99-99 is
            // impossible, so this cannot collide with a real identity.
            'national id in free text' => [
                'Patient presented with 29999999999999 on file',
                '29999999999999',
                'national_id',
            ],
            'bare national id' => ['29999999999999', '29999999999999', 'national_id'],
            'egyptian mobile' => ['call 01099999999 to confirm', '01099999999', 'phone'],
            'international mobile' => ['+201099999999', '201099999999', 'phone'],
            'jwt' => [
                'Authorization failed for eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.abcdefghijklmnop',
                'eyJhbGciOiJIUzI1NiJ9',
                'jwt',
            ],
            'bearer header' => ['Bearer aaaaabbbbbcccccdddddeeeee', 'aaaaabbbbbcccccdddddeeeee', 'bearer'],
            'sanctum style token' => [
                '12|aaaaaaaaaabbbbbbbbbbccccccccccdddddddddd',
                'aaaaaaaaaabbbbbbbbbb',
                'api_token',
            ],
            'email' => [
                'contact synthetic.patient@example.invalid now',
                'synthetic.patient@example.invalid',
                'email',
            ],
            'presigned url signature' => [
                'https://s3.invalid/o?X-Amz-Signature=deadbeefdeadbeef&x=1',
                'X-Amz-Signature=deadbeefdeadbeef',
                'signed_url',
            ],
            'card number' => ['paid with 4111111111111111', '4111111111111111', 'card_number'],
            'private key block' => [
                "-----BEGIN RSA PRIVATE KEY-----\nAAAABBBBCCCC\n-----END RSA PRIVATE KEY-----",
                'AAAABBBBCCCC',
                'private_key',
            ],
        ];
    }

    #[Test]
    #[DataProvider('sensitiveValues')]
    public function it_removes_sensitive_values_from_free_text(
        string $input,
        string $mustNotAppear,
        string $expectedRule,
    ): void {
        $output = $this->redactor->redactText($input);

        $this->assertStringNotContainsString(
            $mustNotAppear,
            $output,
            'A canary value survived redaction and would have reached a log or trace.',
        );

        $this->assertStringContainsString(
            sprintf('[redacted:%s]', $expectedRule),
            $output,
            sprintf(
                'Expected the "%s" rule to fire. Another rule may be masking it, which would leave "%s" '
                . 'untested and free to regress silently.',
                $expectedRule,
                $expectedRule,
            ),
        );
    }

    #[Test]
    #[DataProvider('sensitiveValues')]
    public function it_detects_sensitive_values(
        string $input,
        string $mustNotAppear,
        string $expectedRule,
    ): void {
        $this->assertTrue(
            $this->redactor->containsSensitiveValue($input),
            'containsSensitiveValue() failed to flag a canary, so the pre-export assertion would not fire.',
        );
    }

    // -------------------------------------------------------- key matching

    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            'snake case' => ['national_id'],
            'camel case' => ['nationalId'],
            'kebab case' => ['national-id'],
            'title case with space' => ['National ID'],
            'password' => ['password'],
            'authorization' => ['Authorization'],
            'clinical note' => ['clinical_note'],
            'lab result' => ['lab_result'],
            'object key' => ['object_key'],
            'prompt' => ['prompt'],
            'card number' => ['card_number'],
            'otp' => ['otp'],
        ];
    }

    #[Test]
    #[DataProvider('sensitiveKeys')]
    public function it_recognises_sensitive_keys_regardless_of_naming_style(string $key): void
    {
        $this->assertTrue(
            $this->redactor->isSensitiveKey($key),
            sprintf('Key "%s" was not recognised as sensitive.', $key),
        );
    }

    #[Test]
    public function it_redacts_values_by_key_while_preserving_the_key(): void
    {
        $output = $this->redactor->redactArray([
            'patient_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
            'national_id' => '29999999999999',
            'password' => 'hunter2-not-a-real-password',
            'clinical_note' => 'synthetic complaint text',
        ]);

        // Keys survive so a log line stays searchable and diagnosable.
        $this->assertArrayHasKey('national_id', $output);
        $this->assertArrayHasKey('clinical_note', $output);

        $this->assertSame('[redacted:nationalid]', $output['national_id']);
        $this->assertSame('[redacted:password]', $output['password']);
        $this->assertSame('[redacted:clinicalnote]', $output['clinical_note']);

        // A pseudonymous identifier is exactly what telemetry is supposed to
        // carry, so it must survive untouched.
        $this->assertSame('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10', $output['patient_id']);
    }

    #[Test]
    public function it_redacts_sensitive_values_nested_in_structures(): void
    {
        $output = $this->redactor->redactArray([
            'request' => [
                'headers' => ['authorization' => 'Bearer aaaaabbbbbcccccdddddeeeee'],
                'body' => ['note' => 'contact patient on 01099999999'],
            ],
        ]);

        $encoded = json_encode($output, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('aaaaabbbbbcccccdddddeeeee', $encoded);
        $this->assertStringNotContainsString('01099999999', $encoded);
    }

    // ------------------------------------------------- must NOT over-redact

    #[Test]
    public function it_preserves_ordinary_operational_values(): void
    {
        $output = $this->redactor->redactArray([
            'correlation_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
            'status' => 'PENDING',
            'attempts' => 3,
            'duration_ms' => 42,
            'event_type' => 'platform.diagnostics_round_trip_recorded',
            'service' => 'core-api',
            'version' => '0.1.0',
        ]);

        // A redactor that scrubs everything passes the canary half of this
        // suite and makes telemetry worthless. These must survive verbatim.
        $this->assertSame('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10', $output['correlation_id']);
        $this->assertSame('PENDING', $output['status']);
        $this->assertSame(3, $output['attempts']);
        $this->assertSame(42, $output['duration_ms']);
        $this->assertSame('platform.diagnostics_round_trip_recorded', $output['event_type']);
        $this->assertSame('core-api', $output['service']);
        $this->assertSame('0.1.0', $output['version']);
    }

    #[Test]
    public function it_does_not_flag_ordinary_text_as_sensitive(): void
    {
        $this->assertFalse($this->redactor->containsSensitiveValue('outbox backlog is 12 events'));
        $this->assertFalse($this->redactor->containsSensitiveValue('appointment.booked'));
        $this->assertFalse($this->redactor->containsSensitiveValue('duration 250 ms'));
    }

    // -------------------------------------------------------------- bounds

    #[Test]
    public function it_bounds_recursion_depth(): void
    {
        // A deeply nested payload is cheap to send and expensive to process.
        // Unbounded recursion inside the logging path is a self-inflicted
        // outage, so depth must terminate rather than exhaust the stack.
        $deep = ['leaf' => 'value'];
        for ($i = 0; $i < 200; $i++) {
            $deep = ['nested' => $deep];
        }

        $output = $this->redactor->redactArray($deep);

        $encoded = json_encode($output, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('max depth reached', $encoded);
    }

    #[Test]
    public function it_truncates_very_long_text(): void
    {
        $output = $this->redactor->redactText(str_repeat('a', 100_000));

        $this->assertStringContainsString('[truncated]', $output);
        $this->assertLessThan(100_000, strlen($output));
    }

    #[Test]
    public function it_does_not_walk_into_objects(): void
    {
        // Reflecting into an arbitrary object inside the logging path can
        // trigger lazy loading or a magic getter with side effects. The class
        // name is a safe, useful breadcrumb instead.
        $output = $this->redactor->redactArray(['subject' => new \stdClass()]);

        $this->assertSame('[object:stdClass]', $output['subject']);
    }
}
