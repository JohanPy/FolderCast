<?php

declare(strict_types=1);

namespace OCA\FolderCast\Tests\Unit\Http;

use OCA\FolderCast\Http\RangeStreamResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\IOutput;
use PHPUnit\Framework\TestCase;

class RangeStreamResponseTest extends TestCase
{
    /**
     * @dataProvider rangeDataProvider
     */
    public function testParseRange(?string $header, int $size, array|null|false $expected): void
    {
        $result = RangeStreamResponse::parseRange($header, $size);
        $this->assertSame($expected, $result);
    }

    public static function rangeDataProvider(): array
    {
        return [
            'null header' => [null, 1000, null],
            'empty header' => ['', 1000, null],
            'non-bytes prefix' => ['items=0-10', 1000, null],
            'standard range 0-1023' => ['bytes=0-1023', 2000, [0, 1023]],
            'standard range case-insensitive' => ['Bytes=0-1023', 2000, [0, 1023]],
            'standard range with spaces around equals' => ['bytes = 0-1023', 2000, [0, 1023]],
            'standard range clamped to size-1' => ['bytes=0-1023', 500, [0, 499]],
            'prefix range 500-' => ['bytes=500-', 2000, [500, 1999]],
            'suffix range -500' => ['bytes=-500', 2000, [1500, 1999]],
            'suffix range larger than size' => ['bytes=-3000', 2000, [0, 1999]],
            'multiple ranges (ignored, return null for 200)' => ['bytes=0-100,200-300', 2000, null],
            'invalid start > end' => ['bytes=1000-500', 2000, false],
            'start >= size' => ['bytes=2000-', 2000, false],
            'start >= size in range' => ['bytes=2000-2500', 2000, false],
            'negative start in standard' => ['bytes=-', 2000, false],
            'zero size suffix' => ['bytes=-500', 0, false],
            'zero suffix' => ['bytes=-0', 2000, false],
            'garbage text' => ['bytes=abc-def', 2000, false],
        ];
    }

    public function testCallbackOutputsRange(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '0123456789ABCDEF');
        rewind($stream);

        $response = new RangeStreamResponse($stream, 4, 6);
        $outputMock = $this->createMock(IOutput::class);
        $outputMock->method('getHttpResponseCode')->willReturn(Http::STATUS_PARTIAL_CONTENT);

        ob_start();
        $response->callback($outputMock);
        $content = ob_get_clean();

        $this->assertSame('456789', $content);
    }

    public function testCallbackDoesNothingOn304(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '0123456789');
        rewind($stream);

        $response = new RangeStreamResponse($stream, 0, 5);
        $outputMock = $this->createMock(IOutput::class);
        $outputMock->method('getHttpResponseCode')->willReturn(Http::STATUS_NOT_MODIFIED);

        ob_start();
        $response->callback($outputMock);
        $content = ob_get_clean();

        $this->assertSame('', $content);
    }
}
