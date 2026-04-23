<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Unit\Message;

use Bk203\Vici\Exception\ProtocolException;
use Bk203\Vici\Message\MessageDecoder;
use Bk203\Vici\Message\MessageEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageEncoder::class)]
#[CoversClass(MessageDecoder::class)]
final class MessageCodecTest extends TestCase
{
    public function testEncodesProtocolReadmeExampleByteForByte(): void
    {
        $tree = [
            'key1' => 'value1',
            'section1' => [
                'sub-section' => [
                    'key2' => 'value2',
                ],
                'list1' => ['item1', 'item2'],
            ],
        ];

        $expected = self::bytes([
            3, 4, 'k', 'e', 'y', '1', 0, 6, 'v', 'a', 'l', 'u', 'e', '1',
            1, 8, 's', 'e', 'c', 't', 'i', 'o', 'n', '1',
            1, 11, 's', 'u', 'b', '-', 's', 'e', 'c', 't', 'i', 'o', 'n',
            3, 4, 'k', 'e', 'y', '2', 0, 6, 'v', 'a', 'l', 'u', 'e', '2',
            2,
            4, 5, 'l', 'i', 's', 't', '1',
            5, 0, 5, 'i', 't', 'e', 'm', '1',
            5, 0, 5, 'i', 't', 'e', 'm', '2',
            6,
            2,
        ]);

        $encoder = new MessageEncoder();
        self::assertSame(bin2hex($expected), bin2hex($encoder->encode($tree)));
    }

    public function testDecodesProtocolReadmeExample(): void
    {
        $bytes = self::bytes([
            3, 4, 'k', 'e', 'y', '1', 0, 6, 'v', 'a', 'l', 'u', 'e', '1',
            1, 8, 's', 'e', 'c', 't', 'i', 'o', 'n', '1',
            1, 11, 's', 'u', 'b', '-', 's', 'e', 'c', 't', 'i', 'o', 'n',
            3, 4, 'k', 'e', 'y', '2', 0, 6, 'v', 'a', 'l', 'u', 'e', '2',
            2,
            4, 5, 'l', 'i', 's', 't', '1',
            5, 0, 5, 'i', 't', 'e', 'm', '1',
            5, 0, 5, 'i', 't', 'e', 'm', '2',
            6,
            2,
        ]);

        $decoder = new MessageDecoder();
        self::assertSame(
            [
                'key1' => 'value1',
                'section1' => [
                    'sub-section' => ['key2' => 'value2'],
                    'list1' => ['item1', 'item2'],
                ],
            ],
            $decoder->decode($bytes),
        );
    }

    /**
     * @param array<array-key, mixed> $tree
     */
    #[DataProvider('roundTripProvider')]
    public function testRoundTrip(array $tree, mixed $expectedDecoded = null): void
    {
        $encoder = new MessageEncoder();
        $decoder = new MessageDecoder();
        self::assertSame(
            $expectedDecoded ?? $tree,
            $decoder->decode($encoder->encode($tree)),
        );
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1?: mixed}>
     */
    public static function roundTripProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'flat key values' => [['a' => '1', 'b' => 'two']];
        yield 'empty list' => [['xs' => []]];
        yield 'mixed list and section' => [[
            'section' => ['k' => 'v'],
            'list' => ['x', 'y'],
        ]];
        yield 'deeply nested' => [[
            'a' => ['b' => ['c' => ['d' => ['e' => 'leaf']]]],
        ]];
        yield 'utf-8 values' => [[
            'greeting' => 'héllo — wörld 🌍',
        ]];
        yield 'booleans normalize to yes/no' => [
            ['ok' => true, 'nope' => false],
            ['ok' => 'yes', 'nope' => 'no'],
        ];
        yield 'numerics stringified' => [
            ['i' => 42, 'f' => 3.5],
            ['i' => '42', 'f' => '3.5'],
        ];
        yield 'null values skipped' => [
            ['keep' => 'x', 'drop' => null],
            ['keep' => 'x'],
        ];
    }

    public function testValueAtMaximumLengthRoundTrips(): void
    {
        $big = str_repeat('a', MessageEncoder::MAX_VALUE_LENGTH);
        $tree = ['blob' => $big];

        $encoder = new MessageEncoder();
        $decoder = new MessageDecoder();
        self::assertSame($tree, $decoder->decode($encoder->encode($tree)));
    }

    public function testValueOverMaximumLengthThrows(): void
    {
        $tree = ['blob' => str_repeat('a', MessageEncoder::MAX_VALUE_LENGTH + 1)];
        $this->expectException(ProtocolException::class);
        (new MessageEncoder())->encode($tree);
    }

    public function testNameAtMaximumLengthRoundTrips(): void
    {
        $name = str_repeat('x', MessageEncoder::MAX_NAME_LENGTH);
        $tree = [$name => 'v'];

        $encoder = new MessageEncoder();
        $decoder = new MessageDecoder();
        self::assertSame($tree, $decoder->decode($encoder->encode($tree)));
    }

    public function testNameOverMaximumLengthThrows(): void
    {
        $tree = [str_repeat('x', MessageEncoder::MAX_NAME_LENGTH + 1) => 'v'];
        $this->expectException(ProtocolException::class);
        (new MessageEncoder())->encode($tree);
    }

    public function testNestedListRejected(): void
    {
        $this->expectException(ProtocolException::class);
        (new MessageEncoder())->encode(['xs' => [['nested']]]);
    }

    public function testNullListItemRejected(): void
    {
        $this->expectException(ProtocolException::class);
        (new MessageEncoder())->encode(['xs' => [null]]);
    }

    public function testRootIndexedArrayRejected(): void
    {
        $this->expectException(ProtocolException::class);
        (new MessageEncoder())->encode(['ok', 'fail']);
    }

    public function testDecoderRejectsUnterminatedSection(): void
    {
        $bytes = self::bytes([1, 1, 's']);
        $this->expectException(ProtocolException::class);
        (new MessageDecoder())->decode($bytes);
    }

    public function testDecoderRejectsTrailingBytes(): void
    {
        $bytes = self::bytes([3, 1, 'a', 0, 1, 'v', 0x42]);
        $this->expectException(ProtocolException::class);
        (new MessageDecoder())->decode($bytes);
    }

    public function testDecoderRejectsUnknownElementType(): void
    {
        $bytes = self::bytes([0x55]);
        $this->expectException(ProtocolException::class);
        (new MessageDecoder())->decode($bytes);
    }

    public function testDecoderRejectsDuplicateKey(): void
    {
        $bytes = self::bytes([
            3, 1, 'k', 0, 1, 'a',
            3, 1, 'k', 0, 1, 'b',
        ]);
        $this->expectException(ProtocolException::class);
        (new MessageDecoder())->decode($bytes);
    }

    /**
     * Build a raw byte string from a mix of integer (byte values in 0..255)
     * and single-character string fragments.
     *
     * @param list<int|string> $parts
     */
    private static function bytes(array $parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            if (\is_int($p)) {
                \assert($p >= 0 && $p <= 255);
                $out .= \chr($p);
            } else {
                $out .= $p;
            }
        }
        return $out;
    }
}
