<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\JavaScriptInjectionSubscriber;
use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for JavaScriptInjectionSubscriber.
 *
 * Tests automatic JavaScript SDK injection into HTML responses including:
 * - Enabled/disabled states
 * - Auto-inject configuration
 * - HTML vs non-HTML responses
 * - Main vs sub-requests
 * - Script injection placement
 * - Error handling
 */
class JavaScriptInjectionSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsReturnsResponse(): void
    {
        $events = JavaScriptInjectionSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
        $this->assertSame(['onKernelResponse', -10], $events[KernelEvents::RESPONSE]);
    }

    public function testOnKernelResponseSkipsWhenDisabled(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->expects($this->never())->method('renderInit');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: false, // Disabled
            twigExtension: $twigExtension
        );

        $event = $this->createResponseEvent('<html><body></body></html>');
        $subscriber->onKernelResponse($event);

        // Content should not be modified
        $this->assertSame('<html><body></body></html>', $event->getResponse()->getContent());
    }

    public function testOnKernelResponseSkipsWhenAutoInjectDisabled(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->expects($this->never())->method('renderInit');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: false, // Auto-inject disabled
            enabled: true,
            twigExtension: $twigExtension
        );

        $event = $this->createResponseEvent('<html><body></body></html>');
        $subscriber->onKernelResponse($event);

        // Content should not be modified
        $this->assertSame('<html><body></body></html>', $event->getResponse()->getContent());
    }

    public function testOnKernelResponseSkipsSubRequests(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->expects($this->never())->method('renderInit');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $event = $this->createResponseEvent(
            '<html><body></body></html>',
            HttpKernelInterface::SUB_REQUEST
        );

        $subscriber->onKernelResponse($event);

        // Content should not be modified
        $this->assertSame('<html><body></body></html>', $event->getResponse()->getContent());
    }

    public function testOnKernelResponseSkipsNonHtmlResponses(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->expects($this->never())->method('renderInit');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $response = new Response('{"test": "json"}');
        $response->headers->set('Content-Type', 'application/json');

        $event = $this->createResponseEventWithResponse($response);
        $subscriber->onKernelResponse($event);

        // Content should not be modified
        $this->assertSame('{"test": "json"}', $event->getResponse()->getContent());
    }

    public function testOnKernelResponseSkipsWhenNoBodyTag(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->expects($this->never())->method('renderInit');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $event = $this->createResponseEvent('<html><div>No body tag</div></html>');
        $subscriber->onKernelResponse($event);

        // Content should not be modified
        $this->assertSame('<html><div>No body tag</div></html>', $event->getResponse()->getContent());
    }

    public function testOnKernelResponseInjectsScriptBeforeBodyTag(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn('<script>console.log("test");</script>');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $html = '<html><head></head><body><h1>Test</h1></body></html>';
        $event = $this->createResponseEvent($html);

        $subscriber->onKernelResponse($event);

        $modifiedContent = $event->getResponse()->getContent();

        // Script should be injected before </body> (may have newline between)
        $this->assertStringContainsString('<script>console.log("test");</script>', $modifiedContent);

        // Verify script comes before closing body tag
        $scriptPos = strpos($modifiedContent, '<script>console.log("test");</script>');
        $bodyPos = strpos($modifiedContent, '</body>');
        $this->assertNotFalse($scriptPos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($bodyPos, $scriptPos, 'Script should appear before </body>');

        // Original content should still be there
        $this->assertStringContainsString('<h1>Test</h1>', $modifiedContent);
    }

    public function testOnKernelResponseHandlesCaseInsensitiveBodyTag(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn('<script>test</script>');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        // Test with uppercase BODY tag
        $html = '<HTML><HEAD></HEAD><BODY><H1>Test</H1></BODY></HTML>';
        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEventWithResponse($response);

        $subscriber->onKernelResponse($event);

        $modifiedContent = $event->getResponse()->getContent();

        // Script should be injected before </BODY> (case-insensitive, may have newline between)
        $this->assertStringContainsString('<script>test</script>', $modifiedContent);

        // Verify script comes before closing body tag (case-insensitive)
        $scriptPos = strpos($modifiedContent, '<script>test</script>');
        $bodyPos = stripos($modifiedContent, '</BODY>');
        $this->assertNotFalse($scriptPos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($bodyPos, $scriptPos, 'Script should appear before </BODY>');
    }

    public function testOnKernelResponseSkipsWhenScriptIsEmpty(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn(''); // Empty script

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $html = '<html><body></body></html>';
        $event = $this->createResponseEvent($html);

        $subscriber->onKernelResponse($event);

        // Content should not be modified
        $this->assertSame($html, $event->getResponse()->getContent());
    }

    public function testOnKernelResponseHandlesExceptionGracefully(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willThrowException(new \RuntimeException('Test exception'));

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $html = '<html><body></body></html>';
        $event = $this->createResponseEvent($html);

        // Should not throw
        $subscriber->onKernelResponse($event);

        // Content should not be modified due to exception
        $this->assertSame($html, $event->getResponse()->getContent());
    }

    public function testOnKernelResponseWorksWithHtmlContentType(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn('<script>test</script>');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        $event = $this->createResponseEventWithResponse($response);
        $subscriber->onKernelResponse($event);

        $modifiedContent = $event->getResponse()->getContent();

        // Script should be injected
        $this->assertStringContainsString('<script>test</script>', $modifiedContent);
    }

    public function testOnKernelResponseWorksWhenContentTypeNotSet(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn('<script>test</script>');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        // Response with no Content-Type header (common for HTML in development)
        $response = new Response('<html><body></body></html>');
        // Don't set Content-Type header

        $event = $this->createResponseEventWithResponse($response);
        $subscriber->onKernelResponse($event);

        $modifiedContent = $event->getResponse()->getContent();

        // Script should be injected (when no content-type is set, we assume HTML)
        $this->assertStringContainsString('<script>test</script>', $modifiedContent);
    }

    public function testOnKernelResponseInjectsOnlyOnce(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn('<script>test</script>');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $html = '<html><body><div></div></body></html>';
        $event = $this->createResponseEvent($html);

        $subscriber->onKernelResponse($event);

        $modifiedContent = $event->getResponse()->getContent();

        // Script should appear only once
        $scriptCount = substr_count($modifiedContent, '<script>test</script>');
        $this->assertSame(1, $scriptCount);
    }

    public function testOnKernelResponseHandlesMultilineBodyTag(): void
    {
        $twigExtension = $this->createMock(ApplicationLoggerExtension::class);
        $twigExtension->method('renderInit')
            ->willReturn('<script>test</script>');

        $subscriber = new JavaScriptInjectionSubscriber(
            autoInject: true,
            enabled: true,
            twigExtension: $twigExtension
        );

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body class="main">
    <h1>Hello World</h1>
</body>
</html>
HTML;

        $event = $this->createResponseEvent($html);
        $subscriber->onKernelResponse($event);

        $modifiedContent = $event->getResponse()->getContent();

        // Script should be injected before closing body tag (may have newline between)
        $this->assertStringContainsString('<script>test</script>', $modifiedContent);
        $this->assertStringContainsString('<h1>Hello World</h1>', $modifiedContent);

        // Verify script comes before closing body tag
        $scriptPos = strpos($modifiedContent, '<script>test</script>');
        $bodyPos = stripos($modifiedContent, '</body>');
        $this->assertNotFalse($scriptPos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($bodyPos, $scriptPos, 'Script should appear before </body>');
    }

    /**
     * Create a ResponseEvent for testing.
     */
    private function createResponseEvent(
        string $content,
        int $requestType = HttpKernelInterface::MAIN_REQUEST
    ): ResponseEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $response = new Response($content);

        return new ResponseEvent($kernel, $request, $requestType, $response);
    }

    /**
     * Create a ResponseEvent with a specific Response object.
     */
    private function createResponseEventWithResponse(
        Response $response,
        int $requestType = HttpKernelInterface::MAIN_REQUEST
    ): ResponseEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        return new ResponseEvent($kernel, $request, $requestType, $response);
    }
}
