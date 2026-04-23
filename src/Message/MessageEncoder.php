<?php

declare(strict_types=1);

namespace Bk203\Vici\Message;

use Bk203\Vici\Exception\ProtocolException;

/**
 * Encodes a PHP array tree into the VICI message binary encoding.
 *
 * Mapping:
 *  - An associative array becomes a section (root-level is an implicit section).
 *  - A list array (array_is_list) becomes a LIST; its items are encoded as
 *    LIST_ITEM values. Items must be scalars/stringable — lists cannot nest.
 *  - Scalar / stringable values become KEY_VALUE entries.
 *  - Booleans encode as `"yes"` / `"no"` to match the strongSwan/Python/Go
 *    conventions.
 *  - `null` entries are skipped to ease optional-argument construction.
 */
final class MessageEncoder
{
    public const int MAX_NAME_LENGTH = 255;
    public const int MAX_VALUE_LENGTH = 65535;

    /**
     * Encode the given message tree.
     *
     * @param array<array-key, mixed> $message
     */
    public function encode(array $message): string
    {
        $out = '';
        $this->encodeSectionBody($message, $out);

        return $out;
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function encodeSectionBody(array $section, string &$out): void
    {
        foreach ($section as $name => $value) {
            if ($value === null) {
                continue;
            }

            $nameStr = $this->normalizeName($name);

            if (\is_array($value)) {
                if ($value === [] || array_is_list($value)) {
                    $this->encodeList($nameStr, $value, $out);
                } else {
                    $this->encodeSection($nameStr, $value, $out);
                }
                continue;
            }

            $this->encodeKeyValue($nameStr, $value, $out);
        }
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function encodeSection(string $name, array $section, string &$out): void
    {
        $out .= \chr(ElementType::SECTION_START->value);
        $this->writeName($name, $out);
        $this->encodeSectionBody($section, $out);
        $out .= \chr(ElementType::SECTION_END->value);
    }

    /**
     * @param list<mixed> $list
     */
    private function encodeList(string $name, array $list, string &$out): void
    {
        $out .= \chr(ElementType::LIST_START->value);
        $this->writeName($name, $out);
        foreach ($list as $item) {
            if (\is_array($item)) {
                throw new ProtocolException(\sprintf(
                    'VICI list "%s" items must be scalar; nested arrays are not allowed.',
                    $name,
                ));
            }
            if ($item === null) {
                throw new ProtocolException(\sprintf(
                    'VICI list "%s" items must not be null.',
                    $name,
                ));
            }
            $out .= \chr(ElementType::LIST_ITEM->value);
            $this->writeValue($this->normalizeValue($item), $out);
        }
        $out .= \chr(ElementType::LIST_END->value);
    }

    private function encodeKeyValue(string $name, mixed $value, string &$out): void
    {
        $out .= \chr(ElementType::KEY_VALUE->value);
        $this->writeName($name, $out);
        $this->writeValue($this->normalizeValue($value), $out);
    }

    private function writeName(string $name, string &$out): void
    {
        $len = \strlen($name);
        if ($len === 0) {
            throw new ProtocolException('VICI element names must not be empty.');
        }
        if ($len > self::MAX_NAME_LENGTH) {
            throw new ProtocolException(\sprintf(
                'VICI element name exceeds 255 bytes (got %d).',
                $len,
            ));
        }
        $out .= \chr($len) . $name;
    }

    private function writeValue(string $value, string &$out): void
    {
        $len = \strlen($value);
        if ($len > self::MAX_VALUE_LENGTH) {
            throw new ProtocolException(\sprintf(
                'VICI value exceeds 65535 bytes (got %d).',
                $len,
            ));
        }
        $out .= pack('n', $len) . $value;
    }

    private function normalizeName(int|string $name): string
    {
        if (\is_int($name)) {
            throw new ProtocolException(
                'VICI section/key names must be strings; got an integer index. '
                . 'Did you pass a list where a dictionary was expected?',
            );
        }

        return $name;
    }

    private function normalizeValue(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new ProtocolException(\sprintf(
            'Cannot encode VICI value of type %s; expected scalar or Stringable.',
            get_debug_type($value),
        ));
    }
}
