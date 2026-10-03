<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;

/**
 * Helpers for building and inspecting {@see Candidate}s.
 */
final class Cands
{
    private function __construct()
    {
    }

    /**
     * A match with no location (a computed value) that keeps the document and file of `$from`.
     */
    public static function derive(Node $node, ?Candidate $from): Candidate
    {
        if (!$from instanceof Candidate) {
            return new Candidate($node);
        }

        return new Candidate($node, null, null, $from->documentIndex, $from->fileIndex, $from->filename);
    }

    /**
     * A match located under `$parent` at `$key`.
     */
    public static function child(Node $node, Candidate $parent, Node $key): Candidate
    {
        return new Candidate($node, $parent, $key, $parent->documentIndex, $parent->fileIndex, $parent->filename);
    }

    /**
     * The same node with another document index (split_doc).
     */
    public static function withDocument(Candidate $candidate, int $documentIndex): Candidate
    {
        return new Candidate($candidate->node, $candidate->parent, $candidate->key, $documentIndex, $candidate->fileIndex, $candidate->filename);
    }

    /**
     * A copy of the node that keeps the candidate's location.
     */
    public static function copyOf(Candidate $candidate): Candidate
    {
        return new Candidate($candidate->node->deepCopy(), $candidate->parent, $candidate->key, $candidate->documentIndex, $candidate->fileIndex, $candidate->filename);
    }

    /**
     * The date layout `with_dtf` put in force, or null for the default RFC 3339 handling.
     */
    public static function dateLayout(EvaluationContext $context): ?string
    {
        $variable = $context->variables['__dtf'][0] ?? null;

        return $variable instanceof Candidate ? $variable->node->value : null;
    }

    /**
     * A key candidate: it is the key node of a mapping entry (or the index of a sequence item).
     */
    public static function isKey(Candidate $candidate): bool
    {
        return $candidate->key instanceof Node && $candidate->key === $candidate->node;
    }

    /**
     * The node operators work on: a Document is replaced by its root.
     */
    public static function node(Candidate $candidate): Node
    {
        return NodeOps::unwrap($candidate->node);
    }

    /**
     * A Document candidate is replaced by a candidate for its root, so traversal sees a plain root.
     */
    public static function rooted(Candidate $candidate): Candidate
    {
        if (NodeKind::Document === $candidate->node->kind && isset($candidate->node->content[0])) {
            return new Candidate($candidate->node->content[0], $candidate, null, $candidate->documentIndex, $candidate->fileIndex, $candidate->filename);
        }

        return $candidate;
    }

    /**
     * The candidate for the top of the tree this candidate lives in.
     */
    public static function root(Candidate $candidate): Candidate
    {
        $current = $candidate;
        while ($current->parent instanceof Candidate && NodeKind::Document !== $current->parent->node->kind) {
            $current = $current->parent;
        }

        return $current;
    }

    public static function isRoot(Candidate $candidate): bool
    {
        return !$candidate->parent instanceof Candidate || NodeKind::Document === $candidate->parent->node->kind;
    }

    /**
     * The key nodes from the root down to the candidate.
     *
     * @return list<Node>
     */
    public static function pathKeys(Candidate $candidate): array
    {
        $keys    = [];
        $current = $candidate;
        while ($current->parent instanceof Candidate && NodeKind::Document !== $current->parent->node->kind) {
            if ($current->key instanceof Node) {
                $keys[] = $current->key;
            }

            $current = $current->parent;
        }

        return array_reverse($keys);
    }

    /**
     * True when the matches are the roots of several documents (the eval-all shape): operators then see
     * the whole list instead of one match at a time.
     *
     * @param list<Candidate> $matches
     */
    public static function together(array $matches): bool
    {
        if (\count($matches) < 2) {
            return false;
        }

        $seen = [];
        foreach ($matches as $match) {
            if ($match->parent instanceof Candidate) {
                return false;
            }

            $id = $match->fileIndex . ':' . $match->documentIndex;
            if (isset($seen[$id])) {
                return false;
            }

            $seen[$id] = true;
        }

        return true;
    }
}
