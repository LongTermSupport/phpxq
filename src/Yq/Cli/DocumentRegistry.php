<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use SplObjectStorage;

/**
 * Remembers, for every Document node read, the header text slurped ahead of it and whether it was
 * synthesised for an empty input. Evaluator results are looked up here by identity, so a result that is
 * still a whole document prints with its header.
 */
final class DocumentRegistry
{
    /** @var SplObjectStorage<Node, array{string, bool}> */
    private SplObjectStorage $documents;

    public function __construct()
    {
        $this->documents = new SplObjectStorage();
    }

    public function register(Node $document, string $header, bool $synthetic): void
    {
        $this->documents[$document] = [$header, $synthetic];
    }

    public function headerFor(Node $node): string
    {
        return isset($this->documents[$node]) ? $this->documents[$node][0] : '';
    }

    /**
     * True for a document synthesised for empty input that still holds nothing: there is nothing to print
     * for it but its header.
     */
    public function isUntouchedEmpty(Node $node): bool
    {
        if (!isset($this->documents[$node]) || !$this->documents[$node][1]) {
            return false;
        }

        $root = $node->root();

        return NodeKind::Scalar === $root->kind
            && '' === $root->value
            && '!!null' === $root->tag
            && '' === $node->headComment . $node->lineComment . $node->footComment
            && '' === $root->headComment . $root->lineComment . $root->footComment;
    }
}
