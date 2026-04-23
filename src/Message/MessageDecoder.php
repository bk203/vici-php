<?php

declare(strict_types=1);

namespace Bk203\Vici\Message;

use Bk203\Vici\Exception\ProtocolException;

/**
 * Decodes the VICI message binary encoding into a PHP array tree.
 *
 * Sections (including the implicit root) become associative arrays. Lists
 * become list arrays of strings. Key-value leaves are returned as strings,
 * preserving the raw byte payload (values are opaque blobs at the protocol
 * level).
 */
final class MessageDecoder
{
    /**
     * @return array<string, mixed>
     */
    public function decode(string $bytes): array
    {
        $pos = 0;
        $result = $this->decodeSection($bytes, $pos, isRoot: true);

        if ($pos !== \strlen($bytes)) {
            throw new ProtocolException(\sprintf(
                'VICI message decoder found %d unexpected trailing bytes.',
                \strlen($bytes) - $pos,
            ));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSection(string $bytes, int &$pos, bool $isRoot): array
    {
        $section = [];
        $total = \strlen($bytes);

        while ($pos < $total) {
            $typeByte = \ord($bytes[$pos]);
            $type = ElementType::tryFrom($typeByte);
            if ($type === null) {
                throw new ProtocolException(\sprintf(
                    'Unknown VICI element type 0x%02x at offset %d.',
                    $typeByte,
                    $pos,
                ));
            }
            $pos++;

            switch ($type) {
                case ElementType::SECTION_START:
                    $name = $this->readName($bytes, $pos);
                    if (\array_key_exists($name, $section)) {
                        throw new ProtocolException(\sprintf(
                            'Duplicate section/key "%s" in VICI message.',
                            $name,
                        ));
                    }
                    $section[$name] = $this->decodeSection($bytes, $pos, isRoot: false);
                    break;

                case ElementType::SECTION_END:
                    if ($isRoot) {
                        throw new ProtocolException(
                            'Unexpected SECTION_END at root level of VICI message.',
                        );
                    }
                    return $section;

                case ElementType::KEY_VALUE:
                    $name = $this->readName($bytes, $pos);
                    $value = $this->readValue($bytes, $pos);
                    if (\array_key_exists($name, $section)) {
                        throw new ProtocolException(\sprintf(
                            'Duplicate key "%s" in VICI section.',
                            $name,
                        ));
                    }
                    $section[$name] = $value;
                    break;

                case ElementType::LIST_START:
                    $name = $this->readName($bytes, $pos);
                    if (\array_key_exists($name, $section)) {
                        throw new ProtocolException(\sprintf(
                            'Duplicate list/key "%s" in VICI section.',
                            $name,
                        ));
                    }
                    $section[$name] = $this->decodeList($bytes, $pos, $name);
                    break;

                case ElementType::LIST_END:
                case ElementType::LIST_ITEM:
                    throw new ProtocolException(\sprintf(
                        'Unexpected VICI element %s outside of a list.',
                        $type->name,
                    ));
            }
        }

        if (!$isRoot) {
            throw new ProtocolException(
                'Unterminated VICI section: reached end of message without SECTION_END.',
            );
        }

        return $section;
    }

    /**
     * @return list<string>
     */
    private function decodeList(string $bytes, int &$pos, string $listName): array
    {
        $list = [];
        $total = \strlen($bytes);

        while ($pos < $total) {
            $typeByte = \ord($bytes[$pos]);
            $type = ElementType::tryFrom($typeByte);
            if ($type === null) {
                throw new ProtocolException(\sprintf(
                    'Unknown VICI element type 0x%02x inside list "%s" at offset %d.',
                    $typeByte,
                    $listName,
                    $pos,
                ));
            }
            $pos++;

            switch ($type) {
                case ElementType::LIST_ITEM:
                    $list[] = $this->readValue($bytes, $pos);
                    break;

                case ElementType::LIST_END:
                    return $list;

                default:
                    throw new ProtocolException(\sprintf(
                        'Unexpected VICI element %s inside list "%s".',
                        $type->name,
                        $listName,
                    ));
            }
        }

        throw new ProtocolException(\sprintf(
            'Unterminated VICI list "%s": reached end of message without LIST_END.',
            $listName,
        ));
    }

    private function readName(string $bytes, int &$pos): string
    {
        if ($pos >= \strlen($bytes)) {
            throw new ProtocolException('Truncated VICI message: expected name length byte.');
        }
        $len = \ord($bytes[$pos]);
        $pos++;
        if ($pos + $len > \strlen($bytes)) {
            throw new ProtocolException(\sprintf(
                'Truncated VICI name: need %d bytes but only %d remaining.',
                $len,
                \strlen($bytes) - $pos,
            ));
        }
        if ($len === 0) {
            throw new ProtocolException('VICI element names must not be empty.');
        }
        $name = substr($bytes, $pos, $len);
        $pos += $len;

        return $name;
    }

    private function readValue(string $bytes, int &$pos): string
    {
        if ($pos + 2 > \strlen($bytes)) {
            throw new ProtocolException('Truncated VICI message: expected 16-bit value length.');
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', $bytes, $pos);
        $len = $unpacked[1];
        $pos += 2;
        if ($pos + $len > \strlen($bytes)) {
            throw new ProtocolException(\sprintf(
                'Truncated VICI value: need %d bytes but only %d remaining.',
                $len,
                \strlen($bytes) - $pos,
            ));
        }
        $value = substr($bytes, $pos, $len);
        $pos += $len;

        return $value;
    }
}
