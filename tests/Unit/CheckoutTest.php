<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\Checkout;
use Bayarkit\GatewayError;
use Bayarkit\PaymentForm;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    private PaymentForm $form;

    protected function setUp(): void
    {
        $this->form = new PaymentForm('https://pay.example/entry', [
            'RefNo' => 'ORD1', 'Signature' => 'abc"><script>alert(1)</script>', 'we"ird' => "it's & <b>",
        ]);
    }

    public function test_escapes_every_field_name_and_value(): void
    {
        $html = Checkout::autoPostHtml($this->form);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('value="abc&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringContainsString('name="we&quot;ird" value="it&apos;s &amp; &lt;b&gt;"', $html);
        $this->assertSame(1, substr_count($html, '<script>'));
        $this->assertStringContainsString('<form method="post" action="https://pay.example/entry"', $html);
    }

    public function test_keeps_a_visible_button_with_your_label(): void
    {
        $html = Checkout::autoPostHtml($this->form, 'Teruskan ke pembayaran');
        $this->assertStringContainsString('<button type="submit">Teruskan ke pembayaran</button>', $html);
        $this->assertStringNotContainsString('<noscript>', $html);
    }

    public function test_the_csp_allows_exactly_its_own_script(): void
    {
        $hash = 'sha256-'.base64_encode(hash('sha256', Checkout::SCRIPT, true));
        $this->assertSame($hash, Checkout::SCRIPT_HASH);
        $this->assertStringContainsString("script-src '{$hash}'", Checkout::HEADERS['Content-Security-Policy']);
        $this->assertArrayNotHasKey('Referrer-Policy', Checkout::HEADERS); // iPay88 checks the Referer
        $this->assertSame('no-store', Checkout::HEADERS['Cache-Control']);
    }

    public function test_the_same_page_as_the_typescript_core(): void
    {
        // Pinned from @bayarkit/core (packages/core/src/checkout.ts): both must keep the same script and hash.
        $ts = (string) file_get_contents(__DIR__.'/../../../packages/core/src/checkout.ts');
        $this->assertStringContainsString("'".Checkout::SCRIPT."'", $ts);
        $this->assertStringContainsString("'".Checkout::SCRIPT_HASH."'", $ts);
    }

    public function test_refuses_a_form_or_url_that_is_not_http(): void
    {
        foreach (['javascript:alert(1)', 'not a url', 'ftp://x/y', '//x/y'] as $bad) {
            try {
                Checkout::autoPostHtml(new PaymentForm($bad, []));
                $this->fail("accepted {$bad}");
            } catch (GatewayError $e) {
                $this->assertSame('INVALID_URL', $e->gatewayCode);
            }
        }
    }
}
