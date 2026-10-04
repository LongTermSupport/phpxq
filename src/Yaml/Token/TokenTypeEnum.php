<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

/**
 * Token types of the YAML scanner. The set follows libyaml's scanner (the basis of go-yaml, which the
 * reference yq uses) plus Comment, so that comments reach the parser instead of being dropped.
 */
enum TokenTypeEnum
{
    /** The first token of every stream; it carries no text. */
    case StreamStart;

    /** The last token of every stream; it carries no text. */
    case StreamEnd;

    /** A `%YAML` or `%TAG` directive line; `value` is the whole line without the `%`. */
    case Directive;

    /** `---` */
    case DocumentStart;

    /** `...` */
    case DocumentEnd;

    /** Opens a block sequence; closed by {@see self::BlockEnd}. */
    case BlockSequenceStart;

    /** Opens a block mapping; closed by {@see self::BlockEnd}. */
    case BlockMappingStart;

    /** Closes the innermost block sequence or mapping. */
    case BlockEnd;

    /** `[` */
    case FlowSequenceStart;

    /** `]` */
    case FlowSequenceEnd;

    /** `{` */
    case FlowMappingStart;

    /** `}` */
    case FlowMappingEnd;

    /** `- ` */
    case BlockEntry;

    /** `,` */
    case FlowEntry;

    /** `?` or the implicit start of a simple key */
    case Key;

    /** `:` */
    case Value;

    /** `*name`; `value` is the name */
    case Alias;

    /** `&name`; `value` is the name */
    case Anchor;

    /** `!tag`, `!!tag`, `!<verbatim>`; `value` is the text as written */
    case Tag;

    /** A scalar; `value` is the decoded text and `style` how it was written */
    case Scalar;

    /** `# text`; `value` is the text including the `#` */
    case Comment;
}
