<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Util;

use ApplicationLogger\Bundle\Util\StackTraceParserTrait;
use PHPUnit\Framework\TestCase;

/**
 * Covers HSB-5: parseStackTrace() caps the number of captured frames so a
 * pathological deep-recursion trace cannot build an unbounded array that is
 * later JSON-encoded.
 */
final class StackTraceParserTraitTest extends TestCase
{
    /**
     * Exposes the trait's private parseStackTrace()/MAX_STACK_FRAMES for testing.
     */
    private function parser(): object
    {
        return new class {
            use StackTraceParserTrait;

            public const FRAME_CAP = self::MAX_STACK_FRAMES;

            /**
             * @return list<array<string, mixed>>
             */
            public function parse(\Throwable $e): array
            {
                return $this->parseStackTrace($e);
            }
        };
    }

    public function testShallowTraceIsNotTruncatedAndHasNoSentinel(): void
    {
        $parser = $this->parser();

        $frames = $parser->parse(new \RuntimeException('shallow'));

        $this->assertLessThanOrEqual($parser::FRAME_CAP, \count($frames));
        foreach ($frames as $frame) {
            $this->assertStringNotContainsString('[truncated:', (string) $frame['function']);
        }
    }

    public function testDeepTraceIsCappedAndMarkedTruncated(): void
    {
        $parser = $this->parser();

        $deep = $this->buildDeepException($parser::FRAME_CAP + 50);

        $this->assertGreaterThan(
            $parser::FRAME_CAP,
            \count($deep->getTrace()),
            'Test setup must produce more frames than the cap',
        );

        $frames = $parser->parse($deep);

        // Cap frames + exactly one truncation sentinel.
        $this->assertCount($parser::FRAME_CAP + 1, $frames);

        // array_reverse puts root cause first; the sentinel is unshifted to the front.
        $this->assertStringContainsString('[truncated:', (string) $frames[0]['function']);
        $this->assertFalse($frames[0]['in_app']);
    }

    private function buildDeepException(int $depth): \Throwable
    {
        $recurse = static function (int $n) use (&$recurse): \Throwable {
            if ($n <= 0) {
                return new \RuntimeException('deep');
            }

            return $recurse($n - 1);
        };

        return $recurse($depth);
    }
}
