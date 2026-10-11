<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;

/**
 * Renders nodes as YAML text the way the reference yq (go-yaml v3) does.
 *
 * Document framing follows yq's printer rather than go-yaml's: a `---` line separates consecutive
 * documents, a document head comment is printed before an explicit `---` marker, and `--no-doc`
 * (`noDocSeparator`) drops every marker and directive.
 *
 * @internal
 */
final readonly class YamlEmitter implements YamlEmitterInterface
{
    public function emit(Node $node, EmitOptions $options = new EmitOptions()): string
    {
        return $this->renderDocument($node, $options);
    }

    private function renderDocument(Node $node, EmitOptions $options): string
    {
        $isDocument = NodeKindEnum::Document === $node->kind;
        $root       = $isDocument && []      === $node->content ? new Node(NodeKindEnum::Scalar, '!!null') : $node->root();
        $directives = $isDocument && '' !== $node->directives ? rtrim($node->directives, "\n") . "\n" : '';
        $markers    = !$options->noDocSeparator;

        if ($options->unwrapScalar && NodeKindEnum::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        $out = '';

        $headComment = $isDocument ? $node->headComment : '';
        if ('' !== $headComment) {
            $out .= new YamlWriter($options)->commentBlock($headComment);
            if (str_ends_with($headComment, "\n")) {
                $out .= "\n";
            }
        }

        if ($markers && $isDocument && ($node->explicitStart || '' !== $directives)) {
            $out .= $directives . "---\n";
        }

        $out .= new YamlWriter($options)->render($root, $isDocument ? $node->footComment : '');

        if ($markers && $isDocument && $node->explicitEnd) {
            $out .= "...\n";
        }

        return $out;
    }
}
