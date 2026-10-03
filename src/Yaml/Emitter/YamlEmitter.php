<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;

/**
 * Renders nodes as YAML text the way the reference yq (go-yaml v3) does.
 *
 * Document framing follows yq's printer rather than go-yaml's: a `---` line separates consecutive
 * documents, a document head comment is printed before an explicit `---` marker, and `--no-doc`
 * (`noDocSeparator`) drops every marker and directive.
 */
final class YamlEmitter implements YamlEmitterInterface
{
    public function emit(Node $node, EmitOptions $options = new EmitOptions()): string
    {
        return $this->renderDocument($node, $options, 0);
    }

    public function emitStream(iterable $nodes, EmitOptions $options = new EmitOptions()): string
    {
        $out   = '';
        $index = 0;
        foreach ($nodes as $node) {
            $out .= $this->renderDocument($node, $options, $index);
            ++$index;
        }

        return $out;
    }

    private function renderDocument(Node $node, EmitOptions $options, int $index): string
    {
        $isDocument = NodeKind::Document === $node->kind;
        $root       = $isDocument && []  === $node->content ? new Node(NodeKind::Scalar, '!!null') : $node->root();
        $directives = $isDocument && '' !== $node->directives ? rtrim($node->directives, "\n") . "\n" : '';
        $markers    = !$options->noDocSeparator;

        $out = '';
        if ($index > 0 && $markers) {
            $out .= '' !== $directives ? "...\n" : "---\n";
        }

        if ($options->unwrapScalar && NodeKind::Scalar === $root->kind && !$this->hasComments($node, $root)) {
            return $out . $root->value . "\n";
        }

        $headComment = $isDocument ? $node->headComment : '';
        if ('' !== $headComment) {
            $out .= new YamlWriter($options)->commentBlock($headComment);
            if (str_ends_with($headComment, "\n")) {
                $out .= "\n";
            }
        }

        if ($markers && $isDocument && $this->needsStartMarker($node, $directives, '' !== $headComment, $index)) {
            $out .= $directives . "---\n";
        }

        $out .= new YamlWriter($options)->render($root, $isDocument ? $node->footComment : '');

        if ($markers && $isDocument && $node->explicitEnd) {
            $out .= "...\n";
        }

        return $out;
    }

    /**
     * The separator line already printed between documents doubles as an explicit start marker, unless a
     * head comment or directives sit between it and the content.
     */
    private function needsStartMarker(Node $document, string $directives, bool $hasHeadComment, int $index): bool
    {
        $explicit = $document->explicitStart || '' !== $directives;
        if (!$explicit) {
            return false;
        }

        return $hasHeadComment || 0 === $index || '' !== $directives;
    }

    private function hasComments(Node $node, Node $root): bool
    {
        return '' !== $root->headComment || '' !== $root->lineComment || '' !== $root->footComment
                                         || (NodeKind::Document === $node->kind && ('' !== $node->headComment || '' !== $node->footComment));
    }
}
