<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Parser;

use Generator;
use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yaml\Token\Scanner;
use LTS\PhpXq\Yaml\Token\ScanToken;

/**
 * Builds Document nodes from the scanner tokens of one YAML stream. The productions follow the libyaml
 * parser as go-yaml drives it, and comments are attached the way go-yaml and the reference yq attach
 * them: the scanner fills the head, line and foot buffers when a token is peeked, and the node
 * created next takes whatever the buffers hold.
 *
 * One instance parses one stream; use {@see YamlParser} as the entry point.
 */
final class StreamParser
{
    private const string LONG_TAG_PREFIX = 'tag:yaml.org,2002:';

    private readonly Scanner $sc;

    /** @var array<string, Node> */
    private array $anchors = [];

    /** @var array<string, string> */
    private array $tagDirectives = [];

    private string $endHead = '';

    private string $endFoot = '';

    public function __construct(string $yaml)
    {
        $this->sc = new Scanner($yaml);
    }

    /**
     * @return Generator<int, Node>
     *
     * @throws YamlSyntaxException
     */
    public function documents(): Generator
    {
        $sc = $this->sc;
        $sc->peek();
        $sc->skip();

        $implicit = true;
        $any      = false;
        while (true) {
            $doc = $this->document($implicit);
            if (!$doc instanceof Node) {
                if (!$any && ('' !== $this->endHead || '' !== $this->endFoot)) {
                    $root              = new Node(NodeKind::Scalar, CoreSchema::TAG_NULL);
                    $root->headComment = $this->endHead;
                    $root->footComment = $this->endFoot;
                    yield Node::document($root);
                }

                return;
            }

            $any = true;

            yield $doc;

            $implicit = false;
        }
    }

    // ---------------------------------------------------------------- documents

    private function document(bool $implicit): ?Node
    {
        $sc = $this->sc;
        $t  = $sc->peek();
        while (ScanToken::DOCUMENT_END === $t->type) {
            $sc->skip();
            $t = $sc->peek();
        }

        $type = $t->type;
        $doc  = new Node(NodeKind::Document);
        if (
            $implicit
            && ScanToken::VERSION_DIRECTIVE !== $type
            && ScanToken::TAG_DIRECTIVE     !== $type
            && ScanToken::DOCUMENT_START    !== $type
            && ScanToken::STREAM_END        !== $type
        ) {
            $this->resetTagDirectives();
            $doc->line   = $t->startLine   + 1;
            $doc->column = $t->startColumn + 1;
            $head        = $sc->headComment;
            if ('' !== $head) {
                [$doc->headComment, $sc->headComment] = $this->splitDocumentHead($head);
            }

            $root = $this->parseNode(true, false);
        } elseif (ScanToken::STREAM_END !== $type) {
            $doc->explicitStart = true;
            $doc->line          = $t->startLine   + 1;
            $doc->column        = $t->startColumn + 1;
            $doc->directives    = $this->processDirectives();
            $t                  = $sc->peek();
            if (ScanToken::DOCUMENT_START !== $t->type) {
                $this->fail('did not find expected <document start>', $t);
            }

            $sc->skip();
            $root = $this->documentContent();
        } else {
            $this->endHead = $sc->headComment;
            $this->endFoot = $sc->footComment;
            $sc->skip();

            return null;
        }

        $doc->content = [$root];
        $t            = $sc->peek();
        if (ScanToken::DOCUMENT_END === $t->type) {
            $sc->skip();
            $doc->explicitEnd = true;
        }

        $foot = $sc->footComment;
        if ('' === $foot) {
            $foot = $sc->headComment;
        }

        $this->clearComments();
        $doc->footComment = $foot;

        return $doc;
    }

    /**
     * Splits the leading comment of an implicit document: everything up to the last blank line is the
     * document head comment, the rest stays for the first node.
     *
     * @return array{string, string}
     */
    private function splitDocumentHead(string $head): array
    {
        if (str_ends_with($head, "\n")) {
            return [rtrim($head, "\n"), ''];
        }

        $at = strrpos($head, "\n\n");
        if (false === $at) {
            return ['', $head];
        }

        return [substr($head, 0, $at), substr($head, $at + 2)];
    }

    private function documentContent(): Node
    {
        $t = $this->sc->peek();
        return match ($t->type) {
            ScanToken::VERSION_DIRECTIVE, ScanToken::TAG_DIRECTIVE, ScanToken::DOCUMENT_START, ScanToken::DOCUMENT_END, ScanToken::STREAM_END => $this->emptyScalar($t->startLine, $t->startColumn),
            default => $this->parseNode(true, false),
        };
    }

    private function resetTagDirectives(): void
    {
        $this->tagDirectives = ['!' => '!', '!!' => self::LONG_TAG_PREFIX];
    }

    private function processDirectives(): string
    {
        $sc = $this->sc;
        $this->resetTagDirectives();
        $lines   = [];
        $version = false;
        $handles = [];
        $t       = $sc->peek();
        while (ScanToken::VERSION_DIRECTIVE === $t->type || ScanToken::TAG_DIRECTIVE === $t->type) {
            if (ScanToken::VERSION_DIRECTIVE === $t->type) {
                if ($version) {
                    $this->fail('found duplicate %YAML directive', $t);
                }

                if ('1.1' !== $t->value) {
                    $this->fail('found incompatible YAML document', $t);
                }

                $version = true;
                $lines[] = '%YAML ' . $t->value;
            } else {
                if (isset($handles[$t->value])) {
                    $this->fail('found duplicate %TAG directive', $t);
                }

                $handles[$t->value]             = true;
                $this->tagDirectives[$t->value] = $t->suffix;
                $lines[]                        = '%TAG ' . $t->value . ' ' . $t->suffix;
            }

            $sc->skip();
            $t = $sc->peek();
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------- nodes

    private function parseNode(bool $block, bool $indentless): Node
    {
        $sc = $this->sc;
        $t  = $sc->peek();
        if (ScanToken::ALIAS === $t->type) {
            $node         = new Node(NodeKind::Alias, '', NodeStyle::Default, $t->value);
            $node->line   = $t->startLine   + 1;
            $node->column = $t->startColumn + 1;
            $target       = $this->anchors[$t->value] ?? null;
            if (!$target instanceof Node) {
                $this->fail("unknown anchor '" . $t->value . "' referenced", $t);
            }

            $node->aliasTarget = $target;
            $this->takeComments($node);
            $sc->skip();

            return $node;
        }

        $startLine = $t->startLine   + 1;
        $startCol  = $t->startColumn + 1;
        $anchor    = '';
        $tag       = '';
        if (ScanToken::ANCHOR === $t->type) {
            $anchor = $t->value;
            $sc->skip();
            $t = $sc->peek();
            if (ScanToken::TAG === $t->type) {
                $tag = $this->resolveTag($t);
                $sc->skip();
                $t = $sc->peek();
            }
        } elseif (ScanToken::TAG === $t->type) {
            $tag = $this->resolveTag($t);
            $sc->skip();
            $t = $sc->peek();
            if (ScanToken::ANCHOR === $t->type) {
                $anchor = $t->value;
                $sc->skip();
                $t = $sc->peek();
            }
        }

        switch ($t->type) {
            case ScanToken::BLOCK_ENTRY:
                if ($indentless) {
                    $node = $this->collection(NodeKind::Sequence, false, CoreSchema::TAG_SEQ, $tag, $anchor, $startLine, $startCol);
                    $this->parseIndentlessSequence($node);

                    return $node;
                }

                break;

            case ScanToken::SCALAR:
                $node = $this->scalarNode($t->value, $t->style, $tag, $startLine, $startCol);
                $this->register($node, $anchor);
                $this->takeComments($node);
                $sc->skip();

                return $node;

            case ScanToken::FLOW_SEQUENCE_START:
                $node = $this->collection(NodeKind::Sequence, true, CoreSchema::TAG_SEQ, $tag, $anchor, $startLine, $startCol);
                $this->takeComments($node);
                $this->parseFlowSequence($node);

                return $node;

            case ScanToken::FLOW_MAPPING_START:
                $node = $this->collection(NodeKind::Mapping, true, CoreSchema::TAG_MAP, $tag, $anchor, $startLine, $startCol);
                $this->takeComments($node);
                $this->parseFlowMapping($node);

                return $node;

            case ScanToken::BLOCK_SEQUENCE_START:
                if ($block) {
                    $node = $this->collection(NodeKind::Sequence, false, CoreSchema::TAG_SEQ, $tag, $anchor, $startLine, $startCol);
                    $this->takeStem($node);
                    $this->parseBlockSequence($node);

                    return $node;
                }

                break;

            case ScanToken::BLOCK_MAPPING_START:
                if ($block) {
                    $node = $this->collection(NodeKind::Mapping, false, CoreSchema::TAG_MAP, $tag, $anchor, $startLine, $startCol);
                    $this->takeStem($node);
                    $this->parseBlockMapping($node);

                    return $node;
                }

                break;

            default:
                break;
        }

        if ('' !== $anchor || '' !== $tag) {
            $node = $this->scalarNode('', ScanToken::PLAIN, $tag, $startLine, $startCol);
            $this->register($node, $anchor);

            return $node;
        }

        $this->fail($block ? 'did not find expected node content' : 'did not find expected node content', $t);
    }

    private function resolveTag(ScanToken $t): string
    {
        $handle = $t->value;
        if ('' === $handle) {
            return $t->suffix;
        }

        $prefix = $this->tagDirectives[$handle] ?? null;
        if (null === $prefix) {
            $this->fail('found undefined tag handle', $t);
        }

        return $prefix . $t->suffix;
    }

    private function register(Node $node, string $anchor): void
    {
        if ('' !== $anchor) {
            $node->anchor           = $anchor;
            $this->anchors[$anchor] = $node;
        }
    }

    private function collection(NodeKind $kind, bool $flow, string $defaultTag, string $tag, string $anchor, int $line, int $column): Node
    {
        $node         = new Node($kind, $defaultTag, $flow ? NodeStyle::Flow : NodeStyle::Default);
        $node->line   = $line;
        $node->column = $column;
        if ('' !== $tag && '!' !== $tag) {
            $node->tag         = $this->shortTag($tag);
            $node->tagExplicit = true;
        }

        $this->register($node, $anchor);

        return $node;
    }

    private function scalarNode(string $value, int $style, string $tag, int $line, int $column): Node
    {
        $nodeStyle = match ($style) {
            ScanToken::SINGLE  => NodeStyle::SingleQuoted,
            ScanToken::DOUBLE  => NodeStyle::DoubleQuoted,
            ScanToken::LITERAL => NodeStyle::Literal,
            ScanToken::FOLDED  => NodeStyle::Folded,
            default            => NodeStyle::Default,
        };

        $explicit = false;
        if ('' !== $tag && '!' !== $tag) {
            $resolved = $this->shortTag($tag);
            $explicit = true;
        } elseif ('!' === $tag) {
            $resolved = '!';
        } elseif (NodeStyle::Default !== $nodeStyle) {
            $resolved = CoreSchema::TAG_STR;
        } else {
            $resolved = ScalarResolver::resolve($value);
        }

        $node              = new Node(NodeKind::Scalar, $resolved, $nodeStyle, $value);
        $node->line        = $line;
        $node->column      = $column;
        $node->tagExplicit = $explicit;

        return $node;
    }

    private function shortTag(string $tag): string
    {
        if (str_starts_with($tag, self::LONG_TAG_PREFIX)) {
            return '!!' . substr($tag, \strlen(self::LONG_TAG_PREFIX));
        }

        return $tag;
    }

    /**
     * A null scalar for a missing key, value or document content; the mark is 0-based.
     */
    private function emptyScalar(int $line, int $column): Node
    {
        $node         = new Node(NodeKind::Scalar, CoreSchema::TAG_NULL);
        $node->line   = $line   + 1;
        $node->column = $column + 1;

        return $node;
    }

    // ---------------------------------------------------------------- comments

    private function takeComments(Node $node): void
    {
        $sc                = $this->sc;
        $node->headComment = $sc->headComment;
        $node->lineComment = $sc->lineComment;
        $node->footComment = $sc->footComment;
        $this->clearComments();
    }

    private function clearComments(): void
    {
        $sc              = $this->sc;
        $sc->headComment = '';
        $sc->lineComment = '';
        $sc->footComment = '';
        $sc->stemComment = '';
    }

    private function takeStem(Node $node): void
    {
        $sc = $this->sc;
        if ('' !== $sc->stemComment) {
            $node->headComment = $sc->stemComment;
            $sc->stemComment   = '';
        }
    }

    /**
     * What the scanner holds for the closing event of a collection: line and foot comment.
     */
    private function takeEnd(Node $node): void
    {
        $sc                = $this->sc;
        $node->lineComment = $sc->lineComment;
        $node->footComment = $sc->footComment;
        $this->clearComments();
    }

    private function splitStem(int $priorLength): void
    {
        if (0 === $priorLength) {
            return;
        }

        $sc = $this->sc;
        $t  = $sc->peek();
        if (ScanToken::BLOCK_SEQUENCE_START !== $t->type && ScanToken::BLOCK_MAPPING_START !== $t->type) {
            return;
        }

        $head            = $sc->headComment;
        $sc->stemComment = substr($head, 0, $priorLength);
        $sc->headComment = \strlen($head) === $priorLength ? '' : substr($head, $priorLength + 1);
    }

    // ---------------------------------------------------------------- block collections

    private function parseBlockSequence(Node $node): void
    {
        $sc = $this->sc;
        $sc->skip();
        while (true) {
            $t = $sc->peek();
            if (ScanToken::BLOCK_ENTRY === $t->type) {
                $mark  = $t;
                $prior = \strlen($sc->headComment);
                $sc->skip();
                $this->splitStem($prior);
                $t = $sc->peek();
                if (ScanToken::BLOCK_ENTRY !== $t->type && ScanToken::BLOCK_END !== $t->type) {
                    $node->content[] = $this->parseNode(true, false);
                } else {
                    $node->content[] = $this->emptyScalar($mark->endLine, $mark->endColumn);
                }

                continue;
            }

            if (ScanToken::BLOCK_END === $t->type) {
                $sc->skip();

                return;
            }

            $this->fail("did not find expected '-' indicator", $t);
        }
    }

    private function parseIndentlessSequence(Node $node): void
    {
        $sc = $this->sc;
        while (true) {
            $t = $sc->peek();
            if (ScanToken::BLOCK_ENTRY !== $t->type) {
                return;
            }

            $mark  = $t;
            $prior = \strlen($sc->headComment);
            $sc->skip();
            $this->splitStem($prior);
            $t = $sc->peek();
            if (
                ScanToken::BLOCK_ENTRY  !== $t->type
                && ScanToken::KEY       !== $t->type
                && ScanToken::VALUE     !== $t->type
                && ScanToken::BLOCK_END !== $t->type
            ) {
                $node->content[] = $this->parseNode(true, false);
            } else {
                $node->content[] = $this->emptyScalar($mark->endLine, $mark->endColumn);
            }
        }
    }

    private function parseBlockMapping(Node $node): void
    {
        $sc = $this->sc;
        $sc->skip();
        while (true) {
            $t = $sc->peek();
            if (ScanToken::KEY === $t->type) {
                $mark = $t;
                $sc->skip();
                $t = $sc->peek();
                if (ScanToken::KEY !== $t->type && ScanToken::VALUE !== $t->type && ScanToken::BLOCK_END !== $t->type) {
                    $key = $this->parseNode(true, true);
                } else {
                    $key = $this->emptyScalar($mark->endLine, $mark->endColumn);
                }
            } elseif (ScanToken::BLOCK_END === $t->type) {
                $this->takeEnd($node);
                $sc->skip();
                if ('' !== $node->footComment && \count($node->content) > 1) {
                    $node->content[\count($node->content) - 2]->footComment  = $node->footComment;
                    $node->footComment                                       = '';
                }

                return;
            } else {
                $this->fail('did not find expected key', $t);
            }

            $node->content[] = $key;
            if ('' !== $key->footComment && \count($node->content) > 2) {
                $node->content[\count($node->content) - 3]->footComment  = $key->footComment;
                $key->footComment                                        = '';
            }

            $t = $sc->peek();
            if (ScanToken::VALUE === $t->type) {
                $mark = $t;
                $sc->skip();
                $t = $sc->peek();
                if (ScanToken::KEY !== $t->type && ScanToken::VALUE !== $t->type && ScanToken::BLOCK_END !== $t->type) {
                    $value = $this->parseNode(true, true);
                } else {
                    $value = $this->emptyScalar($mark->endLine, $mark->endColumn);
                }
            } else {
                $value = $this->emptyScalar($t->startLine, $t->startColumn);
            }

            $node->content[] = $value;
            if ('' === $key->footComment && '' !== $value->footComment) {
                $key->footComment   = $value->footComment;
                $value->footComment = '';
            }
        }
    }

    // ---------------------------------------------------------------- flow collections

    private function parseFlowSequence(Node $node): void
    {
        $sc = $this->sc;
        $sc->skip();

        $first = true;
        while (true) {
            $t = $sc->peek();
            if (ScanToken::FLOW_SEQUENCE_END !== $t->type) {
                if (!$first) {
                    if (ScanToken::FLOW_ENTRY !== $t->type) {
                        $this->fail("did not find expected ',' or ']'", $t);
                    }

                    $sc->skip();
                    $t = $sc->peek();
                }

                $first = false;
                if (ScanToken::KEY === $t->type) {
                    $node->content[] = $this->flowSinglePair($t);

                    continue;
                }

                if (ScanToken::FLOW_SEQUENCE_END !== $t->type) {
                    $node->content[] = $this->parseNode(false, false);

                    continue;
                }
            }

            $this->takeEnd($node);
            $sc->skip();

            return;
        }
    }

    private function flowSinglePair(ScanToken $keyToken): Node
    {
        $sc                = $this->sc;
        $pair              = new Node(NodeKind::Mapping, CoreSchema::TAG_MAP, NodeStyle::Flow);
        $pair->line        = $keyToken->startLine   + 1;
        $pair->column      = $keyToken->startColumn + 1;

        $sc->skip();
        $t = $sc->peek();
        if (ScanToken::VALUE !== $t->type && ScanToken::FLOW_ENTRY !== $t->type && ScanToken::FLOW_SEQUENCE_END !== $t->type) {
            $key = $this->parseNode(false, false);
        } else {
            $key = $this->emptyScalar($t->endLine, $t->endColumn);
        }

        $pair->content[] = $key;
        $t               = $sc->peek();
        if (ScanToken::VALUE === $t->type) {
            $sc->skip();
            $t = $sc->peek();
            if (ScanToken::FLOW_ENTRY !== $t->type && ScanToken::FLOW_SEQUENCE_END !== $t->type) {
                $value = $this->parseNode(false, false);
            } else {
                $value = $this->emptyScalar($t->startLine, $t->startColumn);
            }
        } else {
            $value = $this->emptyScalar($t->startLine, $t->startColumn);
        }

        $pair->content[] = $value;
        if ('' === $key->footComment && '' !== $value->footComment) {
            $key->footComment   = $value->footComment;
            $value->footComment = '';
        }

        return $pair;
    }

    private function parseFlowMapping(Node $node): void
    {
        $sc = $this->sc;
        $sc->skip();

        $first = true;
        while (true) {
            $t = $sc->peek();
            if (ScanToken::FLOW_MAPPING_END !== $t->type) {
                if (!$first) {
                    if (ScanToken::FLOW_ENTRY !== $t->type) {
                        $this->fail("did not find expected ',' or '}'", $t);
                    }

                    $sc->skip();
                    $t = $sc->peek();
                }

                $first = false;
                if (ScanToken::KEY === $t->type) {
                    $sc->skip();
                    $t = $sc->peek();
                    if (ScanToken::VALUE !== $t->type && ScanToken::FLOW_ENTRY !== $t->type && ScanToken::FLOW_MAPPING_END !== $t->type) {
                        $key = $this->parseNode(false, false);
                    } else {
                        $key = $this->emptyScalar($t->startLine, $t->startColumn);
                    }

                    $this->appendFlowPair($node, $key, $this->flowMappingValue(false));

                    continue;
                }

                if (ScanToken::FLOW_MAPPING_END !== $t->type) {
                    $key = $this->parseNode(false, false);
                    $this->appendFlowPair($node, $key, $this->flowMappingValue(true));

                    continue;
                }
            }

            $this->takeEnd($node);
            $sc->skip();

            return;
        }
    }

    private function flowMappingValue(bool $empty): Node
    {
        $sc = $this->sc;
        $t  = $sc->peek();
        if ($empty) {
            return $this->emptyScalar($t->startLine, $t->startColumn);
        }

        if (ScanToken::VALUE === $t->type) {
            $sc->skip();
            $t = $sc->peek();
            if (ScanToken::FLOW_ENTRY !== $t->type && ScanToken::FLOW_MAPPING_END !== $t->type) {
                return $this->parseNode(false, false);
            }
        }

        return $this->emptyScalar($t->startLine, $t->startColumn);
    }

    private function appendFlowPair(Node $node, Node $key, Node $value): void
    {
        $node->content[] = $key;
        $node->content[] = $value;
        if ('' === $key->footComment && '' !== $value->footComment) {
            $key->footComment   = $value->footComment;
            $value->footComment = '';
        }
    }

    // ---------------------------------------------------------------- errors

    /**
     * @throws YamlSyntaxException
     */
    private function fail(string $problem, ScanToken $at): never
    {
        throw new YamlSyntaxException($problem, $at->startLine + 1, $at->startColumn + 1);
    }
}
