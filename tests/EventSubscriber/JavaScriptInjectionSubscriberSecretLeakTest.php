<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\JavaScriptInjectionSubscriber;
use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\Twig\CspNonceProvider;
use ApplicationLogger\Bundle\Twig\ScriptRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

final class JavaScriptInjectionSubscriberSecretLeakTest extends TestCase
{
    public function testInjectedHtmlCarriesPublishableKeyAndNoSecret(): void
    {
        // No Security passed -> unauthenticated. Twig $config uses publishable_key (post-swap).
        //
        // MEANINGFUL leak guard: the secret credentials are deliberately PRESENT in
        // the input config here (api_key + log_token). The assertions below prove
        // they are stripped from the rendered browser config by buildConfig()'s
        // hard-strip backstop — not merely absent because the test omitted them.
        // (The previous version of this test passed only because it never supplied
        // a secret at all, giving false assurance.)
        //
        // Note: api_key is intentionally NOT added to scrub_fields here — scrub
        // field NAMES are forwarded to the browser verbatim (scrubFields:[…]), so
        // the literal token "api_key" legitimately appears there as a field name to
        // scrub. That is harmless (it is not the secret value) but would collide
        // with the name-spelling assertion below; the load-bearing check is that the
        // secret VALUES never appear.
        $renderer = new ScriptRenderer(new CspNonceProvider());
        $twig = new ApplicationLoggerExtension(
            config: [
                'enabled' => true,
                'dsn' => 'https://test-host.com/test-project',
                'publishable_key' => 'pk_test_publishable',
                // Secret credentials that MUST NOT reach the browser (G1). If the
                // services.yaml config-swap or the buildConfig backstop ever
                // regresses, these would leak into the injected <script> JSON.
                'api_key' => 'sk_secret_must_not_leak',
                'log_token' => 'sk_log_must_not_leak',
                'environment' => 'test',
                'release' => 'v1.0.0',
                'debug' => false,
                'scrub_fields' => ['password', 'token'],
            ],
            scriptRenderer: $renderer,
        );

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twig,
        );

        $request = Request::create('/');
        $response = new Response(
            '<html><head></head><body><h1>hi</h1></body></html>',
            Response::HTTP_OK,
            ['Content-Type' => 'text/html'],
        );

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, Kernel::MAIN_REQUEST, $response);

        $subscriber->onKernelResponse($event);

        $html = (string) $response->getContent();

        // The injection happened and carries the world-readable publishable key.
        self::assertStringContainsString('"publishableKey":"pk_test_publishable"', $html);
        self::assertStringContainsString('window.appLogger = logger;', $html);

        // G1: no secret credential KEY of any spelling leaks into the rendered HTML.
        self::assertStringNotContainsString('"apiKey"', $html);
        self::assertStringNotContainsString('api_key', $html);
        self::assertStringNotContainsString('log_token', $html);
        self::assertStringNotContainsString('"logToken"', $html);

        // G1 (the meaningful part): the secret VALUES that were PRESENT in the input
        // config must be stripped from the rendered browser config. These would only
        // be absent if buildConfig() actually removed them — not because the test
        // forgot to supply them.
        self::assertStringNotContainsString(
            'sk_secret_must_not_leak',
            $html,
            'the secret api_key value must never reach the injected browser <script>.'
        );
        self::assertStringNotContainsString(
            'sk_log_must_not_leak',
            $html,
            'the secret log_token value must never reach the injected browser <script>.'
        );
    }
}
