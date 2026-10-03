<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Parser;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;

/**
 * Parses a YAML stream into Document nodes with comments, styles, tags, anchors and aliases preserved.
 *
 * Contract:
 *  - every yielded node has kind Document and exactly one content node;
 *  - plain scalars carry the core-schema tag, quoted and block scalars carry !!str, an explicit tag is
 *    kept in `tag` with `tagExplicit` true;
 *  - an alias node's `aliasTarget` is the very node that carries the anchor (shared reference);
 *  - merge keys (`<<`) stay in the tree as ordinary key/value pairs; resolving them is the evaluator's job;
 *  - comments land on the node they attach to under go-yaml's rules (head, line and foot comments);
 *  - an empty input yields no documents; a stream holding only comments yields one Document whose root
 *    is an empty null scalar with the comments attached;
 *  - documents are yielded lazily so a large stream is not held in memory twice.
 *
 * @api
 */
interface YamlParserInterface
{
    /**
     * @return iterable<Node>
     *
     * @throws YamlSyntaxException
     */
    public function parse(string $yaml): iterable;
}
