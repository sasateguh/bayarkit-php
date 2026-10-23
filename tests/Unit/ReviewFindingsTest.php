<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\CallbackInput;
use Bayarkit\Checkout;
use Bayarkit\Confirm;
use Bayarkit\ConfirmResult;
use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Expected;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\GatewayInfo;
use Bayarkit\Gateways\ToyyibPay;
use Bayarkit\Http\Response;
use Bayarkit\Http\Transport;
use Bayarkit\Money;
use Bayarkit\PaymentRecord;
use Bayarkit\PaymentStatus;
use Bayarkit\VerifiedCallback;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** One test per finding of the independent review (23 Oct 2026), each written from its failure scenario. */
final class ReviewFindingsTest extends TestCase
{
    private static function toyyibpayAnswering(string $body): ToyyibPay
    {
        $transport = new class($body) implements Transport
        {
            public function __construct(private readonly string $body) {}

            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
            {
                return new Response(200, "\t\t\t\t".$this->body);
            }
        };

        return new ToyyibPay('secret', 'cat', baseUrl: 'https://dev.toyyibpay.test', transport: $transport);
    }

    public function test_a_boolean_true_status_is_unknown_as_in_the_typescript_twin_not_paid(): void
    {
        $g = self::toyyibpayAnswering('[{"billpaymentStatus":true,"billpaymentAmount":"10.50","billExternalReferenceNo":"R1"}]');
        $this->assertSame(PaymentStatus::Unknown, $g->getPayment('abc123')->status);
        $result = Confirm::payment($g, new CallbackInput(['billcode' => 'abc123', 'order_id' => 'R1', 'status' => '1', 'refno' => 'x', 'hash' => md5('secret1R1xok')]), new Expected('R1', 1050));
        $this->assertSame(ConfirmResult::NOT_PAID, $result->reason);
    }

    public function test_arrays_where_text_is_expected_are_read_as_empty_not_an_exception(): void
    {
        set_error_handler(static fn (int $no, string $msg) => throw new RuntimeException($msg)); // what Laravel installs
        try {
            $g = self::toyyibpayAnswering('[{"billpaymentStatus":"1","billpaymentAmount":"10.50","billExternalReferenceNo":["R1"],"billPaymentDate":[],"transactionCharge":{"a":1}}]');
            $record = $g->getPayment('abc123');
            $this->assertSame('', $record->reference);
            $this->assertNull($record->paidAt);
            $this->assertNull($record->fee);
        } finally {
            restore_error_handler();
        }
    }

    public function test_verify_callback_never_throws_on_array_fields_from_request_all(): void
    {
        $g = self::toyyibpayAnswering('[]');
        $this->assertNull($g->verifyCallback(new CallbackInput(['hash' => ['x'], 'billcode' => ['y']])));
        $this->assertSame([], (new CallbackInput(['a' => ['b'], 'c' => new \stdClass]))->fields);
    }

    public function test_to_sen_never_overflows(): void
    {
        $this->assertNull(Money::toSen(PHP_INT_MAX));
        $this->assertNull(Money::toSen('99999999999999999999'));
        $this->assertNull(Money::toSen(1e300));
        $this->assertSame(Money::MAX_SEN, Money::toSen('90071992547409.91'));
    }

    public function test_confirm_turns_any_exception_into_a_refusal_never_a_500(): void
    {
        $info = new GatewayInfo('broken', 100, 'docs', '2026-10-23', 'test');
        $throwsOnQuery = new class($info) implements Gateway
        {
            public function __construct(private readonly GatewayInfo $info) {}

            public function info(): GatewayInfo
            {
                return $this->info;
            }

            public function createPayment(CreatePaymentInput $input): CreatedPayment
            {
                throw new RuntimeException('unused');
            }

            public function getPayment(string $id): PaymentRecord
            {
                throw new \TypeError('secret=abc leaked in a message');
            }

            public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
            {
                return $callback->fields === [] ? throw new RuntimeException('boom') : new VerifiedCallback('p1', 'R1', PaymentStatus::Paid, []);
            }
        };
        $r = Confirm::payment($throwsOnQuery, new CallbackInput(['x' => '1']), new Expected('R1', 100));
        $this->assertSame(ConfirmResult::GATEWAY_ERROR, $r->reason);
        $this->assertStringNotContainsString('secret', $r->detail);
        $this->assertSame('broken: getPayment threw TypeError', $r->detail);
        // A verifyCallback that throws is a bug or a broken key, not a forgery (same as the TypeScript twin).
        $thrown = Confirm::payment($throwsOnQuery, new CallbackInput, new Expected('R1', 100));
        $this->assertSame(ConfirmResult::GATEWAY_ERROR, $thrown->reason);
        $this->assertSame('broken: verifyCallback threw RuntimeException', $thrown->detail);
    }

    public function test_a_url_with_control_characters_never_reaches_a_location_header(): void
    {
        foreach (["https://a.test/x\r\nSet-Cookie: s=1", "https://a.test/x y", "https://a.test/\x00"] as $bad) {
            try {
                Checkout::httpUrl($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (GatewayError $e) {
                $this->assertSame('INVALID_URL', $e->gatewayCode);
            }
        }
    }
}
