<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use SplObjectStorage;

/**
 * Remembers, for the root node of every document read, the Document node it came from (which carries
 * `---`, `...` and directive information for output), the header text slurped ahead of it, and whether
 * the document was synthesised for an empty input. Evaluator results are looked up here by identity, so a
 * result that is still a document root prints with its document's framing and header.
 */
final class DocumentRegistry
{
    /** @var SplObjectStorage<Node, array{Node, string, bool}> */
    private SplObjectStorage $roots;

    public function __construct()
    {
        $this->roots = new SplObjectStorage();
    }

    public function register(Node $root, Node $document, string $header, bool $synthetic): void
    {
        $this->roots[$root] = [$document, $header, $synthetic];
    }

    public function documentFor(Node $node): ?Node
    {
        return isset($this->roots[$node]) ? $this->roots[$node][0] : null;
    }

    public function headerFor(Node $node): string
    {
        return isset($this->roots[$node]) ? $this->roots[$node][1] : '';
    }

    /**
     * True for a document synthesised for empty input that still holds nothing: there is nothing to print
     * for it but its header.
     */
    public function isUntouchedEmpty(Node $node): bool
    {
        return isset($this->roots[$node])
            && $this->roots[$node][2]
            && NodeKind::Scalar === $node->kind
            && '' === $node->value
            && '!!null' === $node->tag
            && '' === $node->headComment . $node->lineComment . $node->footComment;
    }
}
