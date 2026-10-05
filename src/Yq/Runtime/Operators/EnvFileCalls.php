<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
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
final readonly class EnvFileCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return BuiltinNameEnum::values(
            BuiltinNameEnum::Env,
            BuiltinNameEnum::Strenv,
            BuiltinNameEnum::Envsubst,
            BuiltinNameEnum::Load,
            BuiltinNameEnum::LoadStr,
            BuiltinNameEnum::Strload,
            BuiltinNameEnum::System,
        );
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $security = $context->services->security;
        if ((BuiltinNameEnum::Env->value === $call->name || BuiltinNameEnum::Strenv->value === $call->name || BuiltinNameEnum::Envsubst->value === $call->name) && $security->disableEnvOperators) {
            throw new EvaluationException('Environment variable operations have been disabled');
        }

        if ((BuiltinNameEnum::Load->value === $call->name || BuiltinNameEnum::LoadStr->value === $call->name || BuiltinNameEnum::Strload->value === $call->name) && $security->disableFileOperators) {
            throw new EvaluationException('File operations have been disabled');
        }

        if (BuiltinNameEnum::System->value === $call->name && !$security->enableSystemOperator) {
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
        switch (BuiltinNameEnum::tryFrom($call->name)) {
            case BuiltinNameEnum::Env:
                Args::require($call, 1);
                $name  = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
                $value = getenv($name);
                if (false === $value) {
                    return Cands::derive(NodeOps::null(), $match);
                }

                return Cands::derive($this->parseValue($value, $context), $match);

            case BuiltinNameEnum::Strenv:
                Args::require($call, 1);
                $name  = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
                $value = getenv($name);
                if (false === $value) {
                    throw new EvaluationException(\sprintf("Value for env variable '%s' not provided in env()", $name));
                }

                return Cands::derive(NodeOps::str($value), $match);

            case BuiltinNameEnum::Envsubst:
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

            case BuiltinNameEnum::Load:
            case BuiltinNameEnum::LoadStr:
            case BuiltinNameEnum::Strload:
                Args::require($call, 1);
                $name = Args::node($call, 0, $context, $evaluator, $match);
                if (!$name instanceof Node || NodeOps::isNull($name)) {
                    throw new EvaluationException('filename expression returned nil');
                }

                $file = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
                if (!file_exists($file) || is_dir($file) || !is_readable($file)) {
                    throw new EvaluationException(\sprintf('failed to load %1$s: open %1$s: no such file or directory', $file));
                }

                $content = (string)file_get_contents($file);
                if (BuiltinNameEnum::Load->value !== $call->name) {
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
        $documents = YamlParser::attempt($context->services->yamlParser, $value, 1);
        if ($documents instanceof YamlSyntaxException || [] === $documents) {
            return NodeOps::str($value);
        }

        return NodeOps::unwrap($documents[0]);
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
