<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use Generator;
use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;

/**
 * Reads input files (or standard input, named `-`) and yields one Candidate per document (the candidate's
 * node is the Document node, which the evaluator treats as transparent), in order, numbering documents
 * per file and files per run. YAML goes straight to the YAML parser; every other
 * format goes through its decoder.
 *
 * An input that yields no document (an empty file, or one holding only a header) yields one synthesised
 * null document, so an expression such as `.a = 1` still has something to build on.
 *
 * @internal
 */
final readonly class SourceDocuments
{
    public function __construct(
        private YamlParserInterface $yamlParser,
        private FormatRegistryInterface $formats,
        private DocumentRegistry $registry,
        private HeaderSplitter $headers = new HeaderSplitter(),
    ) {
    }

    /**
     * @param list<FileInput> $inputs
     * @param resource        $stdin
     * @param bool            $wholeHeader take the whole leading comment run as the header (YAML output)
     *
     * @return Generator<int, Candidate>
     *
     * @throws CliException
     */
    public function read(array $inputs, mixed $stdin, FormatEnum $format, FormatOptions $options, HeaderModeEnum $mode, bool $wholeHeader = true): Generator
    {
        foreach ($inputs as $fileIndex => $input) {
            $content = $input->content ?? $this->contents($input->name, $stdin);
            $header  = '';
            if (FormatEnum::Yaml === $format && (HeaderModeEnum::PerFile === $mode || (HeaderModeEnum::FirstFile === $mode && 0 === $fileIndex))) {
                [$header, $content] = $this->headers->split($content, $wholeHeader);
            }

            $docIndex = 0;
            try {
                $documents = FormatEnum::Yaml === $format
                    ? $this->yamlParser->parse($content)
                    : $this->formats->decoder($format)->decode($content, $options);
                foreach ($documents as $document) {
                    $this->registry->register($document, 0 === $docIndex ? $header : '', false);

                    yield new Candidate($document, null, null, $docIndex, $fileIndex, $input->name);

                    ++$docIndex;
                }
            } catch (YamlSyntaxException|FormatException $e) {
                throw new CliException(\sprintf("bad file '%s': %s", $input->name, $e->getMessage()), 0, $e);
            }

            if (0 === $docIndex) {
                $document = Node::document(new Node(NodeKindEnum::Scalar, '!!null', NodeStyleEnum::Default, ''));
                $this->registry->register($document, $header, true);

                yield new Candidate($document, null, null, 0, $fileIndex, $input->name);
            }
        }
    }

    /**
     * The text of one input: a file's content, or standard input for `-`.
     *
     * @param resource $stdin
     *
     * @throws CliException
     */
    public function contents(string $name, mixed $stdin): string
    {
        if ('-' === $name) {
            $contents = \is_resource($stdin) ? stream_get_contents($stdin) : false;

            return false === $contents ? '' : $contents;
        }

        if (is_dir($name)) {
            throw new CliException(\sprintf('read %s: is a directory', $name));
        }

        if (!file_exists($name)) {
            throw new CliException(\sprintf('open %s: no such file or directory', $name));
        }

        if (!is_readable($name)) {
            throw new CliException(\sprintf('open %s: permission denied', $name));
        }

        $contents = file_get_contents($name);
        if (false === $contents) {
            throw new CliException(\sprintf('open %s: permission denied', $name));
        }

        return $contents;
    }
}
