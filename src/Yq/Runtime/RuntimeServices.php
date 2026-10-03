<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Expression\ExpressionParserInterface;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;

/**
 * The collaborators operators may need beyond the AST: re-parsing (`eval`), decoding and encoding
 * (`from_json`, `@yaml` family, `load`), and the security switches. Built once by the CLI.
 */
final readonly class RuntimeServices
{
    public function __construct(
        public ExpressionParserInterface $expressionParser,
        public YamlParserInterface $yamlParser,
        public FormatRegistryInterface $formats,
        public SecurityOptions $security = new SecurityOptions(),
    ) {
    }
}
