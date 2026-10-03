<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\EnvSubst;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Environment, file and process operators: `env`, `strenv`, `envsubst`, `load`, `load_str`, `system`. They
 * honour the `--security-*` switches.
 */
final class EnvFileCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['env', 'strenv', 'envsubst', 'load', 'load_str', 'strload', 'system'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $security = $context->services->security;
        if (('env' === $call->name || 'strenv' === $call->name || 'envsubst' === $call->name) && $security->disableEnvOperators) {
            throw new EvaluationException('Environment variable operations have been disabled');
        }

        if (('load' === $call->name || 'load_str' === $call->name || 'strload' === $call->name) && $security->disableFileOperators) {
            throw new EvaluationException('File operations have been disabled');
        }

        if ('system' === $call->name && !$security->enableSystemOperator) {
            throw new EvaluationException('System operations are disabled, use --security-enable-system-operator to enable them');
        }

        $out = [];
        foreach ($context->matches as $match) {
            $out[] = $this->one($call, $match, $context, $evaluator);
        }

        return $out;
    }

    private function one(Call $call, Candidate $match, EvaluationContext $context, EvaluatorInterface $evaluator): Candidate
    {
        switch ($call->name) {
            case 'env':
                Args::require($call, 1);
                $name  = Args::string($call, 0, $context, $evaluator, $match) ?? '';
                $value = getenv($name);
                if (false === $value) {
                    return Cands::derive(NodeOps::null(), $match);
                }

                return Cands::derive($this->parseValue($value, $context), $match);

            case 'strenv':
                Args::require($call, 1);
                $name  = Args::string($call, 0, $context, $evaluator, $match) ?? '';
                $value = getenv($name);
                if (false === $value) {
                    throw new EvaluationException(\sprintf("Value for env variable '%s' not provided in env()", $name));
                }

                return Cands::derive(NodeOps::str($value), $match);

            case 'envsubst':
                $node = NodeOps::deref(Cands::node($match));
                if (!NodeOps::isScalar($node)) {
                    throw new EvaluationException('Cannot envsubst a collection');
                }

                $flags = [];
                foreach ($call->arguments as $argument) {
                    self::collectFlags($argument, $flags);
                }

                $out        = $node->deepCopy();
                $out->value = EnvSubst::substitute($node->value, \in_array('nu', $flags, true), \in_array('ne', $flags, true));

                return Cands::derive($out, $match);

            case 'load':
            case 'load_str':
            case 'strload':
                Args::require($call, 1);
                $name = Args::node($call, 0, $context, $evaluator, $match);
                if (!$name instanceof Node || NodeOps::isNull($name)) {
                    throw new EvaluationException('filename expression returned nil');
                }

                $file = Args::string($call, 0, $context, $evaluator, $match) ?? '';
                if (!is_file($file) || !is_readable($file)) {
                    throw new EvaluationException(\sprintf('failed to load %1$s: open %1$s: no such file or directory', $file));
                }

                $content = (string)file_get_contents($file);
                if ('load' !== $call->name) {
                    return Cands::derive(NodeOps::str($content), $match);
                }

                try {
                    foreach ($context->services->yamlParser->parse($content) as $document) {
                        return Cands::derive(NodeOps::unwrap($document), $match);
                    }
                } catch (YamlSyntaxException $exception) {
                    throw new EvaluationException($exception->getMessage(), 0, $exception);
                }

                return Cands::derive(NodeOps::null(), $match);

            default:
                throw new EvaluationException('The system operator is not supported by phpxq (spawning processes is out of scope)');
        }
    }

    private function parseValue(string $value, EvaluationContext $context): Node
    {
        try {
            foreach ($context->services->yamlParser->parse($value) as $document) {
                return NodeOps::unwrap($document);
            }
        } catch (YamlSyntaxException) {
            return NodeOps::str($value);
        }

        return NodeOps::str($value);
    }

    /**
     * @param list<string> $flags
     */
    private static function collectFlags(ExpressionNodeInterface $node, array &$flags): void
    {
        if ($node instanceof Call) {
            $flags[] = $node->name;
        } elseif ($node instanceof Binary && BinaryOperatorEnum::Union === $node->operator) {
            self::collectFlags($node->left, $flags);
            self::collectFlags($node->right, $flags);
        }
    }
}
