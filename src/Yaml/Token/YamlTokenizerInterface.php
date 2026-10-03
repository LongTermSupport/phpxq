<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;

/**
 * Turns YAML text into tokens. Implemented by YamlTokenizer; the parser worker may consume it or scan
 * internally, but the interface is the tested seam.
 *
 * @api
 */
interface YamlTokenizerInterface
{
    /**
     * @return iterable<Token> StreamStart first, StreamEnd last
     *
     * @throws YamlSyntaxException
     */
    public function tokenize(string $yaml): iterable;
}
