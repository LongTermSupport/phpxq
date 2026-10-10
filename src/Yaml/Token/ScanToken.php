<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

/**
 * The scanner's internal token: integer type and style codes and byte offsets, so the hot path builds
 * one small object per token and no enums or per-character values. Lines are 0-based, columns are
 * 0-based and count characters.
 *
 * @internal
 */
final readonly class ScanToken
{
    public const int STREAM_START = 1;

    public const int STREAM_END = 2;

    public const int VERSION_DIRECTIVE = 3;

    public const int TAG_DIRECTIVE = 4;

    public const int DOCUMENT_START = 5;

    public const int DOCUMENT_END = 6;

    public const int BLOCK_SEQUENCE_START = 7;

    public const int BLOCK_MAPPING_START = 8;

    public const int BLOCK_END = 9;

    public const int FLOW_SEQUENCE_START = 10;

    public const int FLOW_SEQUENCE_END = 11;

    public const int FLOW_MAPPING_START = 12;

    public const int FLOW_MAPPING_END = 13;

    public const int BLOCK_ENTRY = 14;

    public const int FLOW_ENTRY = 15;

    public const int KEY = 16;

    public const int VALUE = 17;

    public const int ALIAS = 18;

    public const int ANCHOR = 19;

    public const int TAG = 20;

    public const int SCALAR = 21;

    public const int PLAIN = 1;

    public const int SINGLE = 2;

    public const int DOUBLE = 3;

    public const int LITERAL = 4;

    public const int FOLDED = 5;

    /**
     * `value` is the scalar text, the anchor or alias name, the tag handle, a directive name or version;
     * `suffix` is the tag suffix or the %TAG prefix.
     */
    public function __construct(
        public int $type,
        public int $startIndex,
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
        public string $value = '',
        public string $suffix = '',
        public int $style = 0,
    ) {
    }
}
