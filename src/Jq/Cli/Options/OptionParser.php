<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\FileReader;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonSyntaxException;
use RuntimeException;

/**
 * jq's command line grammar: option clusters (`-nr`), long options, `--args` / `--jsonargs` switching,
 * `--` and the positional order (program first, then files or positional arguments).
 *
 * Options act in the order they appear, as in jq: `-h` or `-V` end parsing on the spot (so `-hV` shows
 * the help and `-Vh` the version), and a refusal is raised when its option is reached.
 *
 * @api
 */
final readonly class OptionParser
{
    public function __construct(
        private JsonDecoderInterface $decoder,
    ) {
    }

    /**
     * @param list<string> $args arguments after `jq`
     *
     * @throws UsageException
     */
    public function parse(array $args): CliOptions
    {
        $program         = null;
        $files           = [];
        $libraries       = [];
        $named           = [];
        $positional      = [];
        $argsDone        = false;
        $furtherFileArgs = true;
        $jsonArgs        = false;
        $flags           = [
            'nullInput'       => false,
            'rawInput'        => false,
            'slurp'           => false,
            'rawOutput'       => false,
            'rawOutput0'      => false,
            'joinOutput'      => false,
            'ascii'           => false,
            'sortKeys'        => false,
            'exitStatus'      => false,
            'seq'             => false,
            'stream'          => false,
            'streamErrors'    => false,
            'unbuffered'      => false,
            'fromFile'        => false,
            'debugDumpDisasm' => false,
        ];
        $color  = null;
        $pretty = true;
        $tab    = false;
        $indent = 2;
        $count  = \count($args);

        for ($i = 0; $i < $count; ++$i) {
            $text = $args[$i];

            if ($argsDone || !$this->looksLikeOption($text)) {
                if (null === $program) {
                    $program = $text;
                } elseif ($furtherFileArgs) {
                    $files[] = $text;
                } elseif ($jsonArgs) {
                    $positional[] = $this->decodeOrRefuse($text, '--jsonargs');
                } else {
                    $positional[] = $text;
                }

                continue;
            }

            if ('--' === $text) {
                $argsDone = true;

                continue;
            }

            if ('-' !== $text[1]) {
                $length = \strlen($text);
                for ($k = 1; $k < $length; ++$k) {
                    switch ($text[$k]) {
                        case 'n':
                            $flags['nullInput'] = true;

                            break;

                        case 'R':
                            $flags['rawInput'] = true;

                            break;

                        case 's':
                            $flags['slurp'] = true;

                            break;

                        case 'c':
                            $pretty = false;
                            $tab    = false;
                            $indent = 0;

                            break;

                        case 'r':
                            $flags['rawOutput'] = true;

                            break;

                        case 'j':
                            $flags['rawOutput']  = true;
                            $flags['joinOutput'] = true;

                            break;

                        case 'a':
                            $flags['ascii'] = true;

                            break;

                        case 'S':
                            $flags['sortKeys'] = true;

                            break;

                        case 'C':
                            $color = true;

                            break;

                        case 'M':
                            $color = false;

                            break;

                        case 'e':
                            $flags['exitStatus'] = true;

                            break;

                        case 'f':
                            $flags['fromFile'] = true;

                            break;

                        case 'b':
                            break;

                        case 'h':
                            return new CliOptions(action: CliAction::Help);

                        case 'V':
                            return new CliOptions(action: CliAction::Version);

                        case 'L':
                            $rest = substr($text, $k + 1);
                            if ('' !== $rest) {
                                $libraries[] = $rest;
                            } elseif ($i + 1 < $count) {
                                $libraries[] = $args[++$i];
                            } else {
                                throw new UsageException('jq: -L takes a parameter: (e.g. -L /search/path or -L/search/path)');
                            }

                            break 2;

                        default:
                            throw new UsageException('jq: Unknown option: ' . $text);
                    }
                }

                continue;
            }

            switch (substr($text, 2)) {
                case 'help':
                    return new CliOptions(action: CliAction::Help);

                case 'version':
                    return new CliOptions(action: CliAction::Version);

                case 'build-configuration':
                    return new CliOptions(action: CliAction::BuildConfiguration);

                case 'null-input':
                    $flags['nullInput'] = true;

                    break;

                case 'raw-input':
                    $flags['rawInput'] = true;

                    break;

                case 'slurp':
                    $flags['slurp'] = true;

                    break;

                case 'compact-output':
                    $pretty = false;
                    $tab    = false;
                    $indent = 0;

                    break;

                case 'raw-output':
                    $flags['rawOutput'] = true;

                    break;

                case 'raw-output0':
                    $flags['rawOutput']  = true;
                    $flags['rawOutput0'] = true;
                    $flags['joinOutput'] = true;

                    break;

                case 'join-output':
                    $flags['rawOutput']  = true;
                    $flags['joinOutput'] = true;

                    break;

                case 'ascii-output':
                    $flags['ascii'] = true;

                    break;

                case 'sort-keys':
                    $flags['sortKeys'] = true;

                    break;

                case 'color-output':
                    $color = true;

                    break;

                case 'monochrome-output':
                    $color = false;

                    break;

                case 'tab':
                    $tab    = true;
                    $pretty = true;
                    $indent = 0;

                    break;

                case 'indent':
                    if ($i + 1 >= $count) {
                        throw new UsageException('jq: --indent takes one parameter');
                    }

                    $value = (int)$args[++$i];
                    if ($value < -1) {
                        throw new UsageException('jq: Cannot indent less than -1 characters');
                    }

                    if ($value > 7) {
                        throw new UsageException('jq: Cannot indent more than 7 characters');
                    }

                    $tab    = -1 === $value;
                    $indent = $tab ? 0 : $value;

                    break;

                case 'unbuffered':
                    $flags['unbuffered'] = true;

                    break;

                case 'stream':
                    $flags['stream'] = true;

                    break;

                case 'stream-errors':
                    $flags['stream']       = true;
                    $flags['streamErrors'] = true;

                    break;

                case 'seq':
                    $flags['seq'] = true;

                    break;

                case 'from-file':
                    $flags['fromFile'] = true;

                    break;

                case 'exit-status':
                    $flags['exitStatus'] = true;

                    break;

                case 'binary':
                case 'debug-trace':
                case 'debug-trace=all':
                    break;

                case 'debug-dump-disasm':
                    $flags['debugDumpDisasm'] = true;

                    break;

                case 'args':
                    $furtherFileArgs = false;
                    $jsonArgs        = false;

                    break;

                case 'jsonargs':
                    $furtherFileArgs = false;
                    $jsonArgs        = true;

                    break;

                case 'arg':
                    if ($i + 2 >= $count) {
                        throw new UsageException('jq: --arg takes two parameters (e.g. --arg varname value)');
                    }

                    $named[$args[$i + 1]] = $args[$i + 2];
                    $i += 2;

                    break;

                case 'argjson':
                    if ($i + 2 >= $count) {
                        throw new UsageException('jq: --argjson takes two parameters (e.g. --argjson varname text)');
                    }

                    $named[$args[$i + 1]] = $this->decodeOrRefuse($args[$i + 2], '--argjson');
                    $i += 2;

                    break;

                case 'slurpfile':
                case 'rawfile':
                    $which = substr($text, 2);
                    if ($i + 2 >= $count) {
                        throw new UsageException(\sprintf('jq: --%s takes two parameters (e.g. --%s varname filename)', $which, $which));
                    }

                    $named[$args[$i + 1]] = $this->loadFile($which, $args[$i + 1], $args[$i + 2]);
                    $i += 2;

                    break;

                default:
                    throw new UsageException('jq: Unknown option: ' . $text);
            }
        }

        return new CliOptions(
            action: CliAction::Run,
            program: $program,
            files: $files,
            libraryPaths: $libraries,
            named: $named,
            positional: $positional,
            nullInput: $flags['nullInput'],
            rawInput: $flags['rawInput'],
            slurp: $flags['slurp'],
            rawOutput: $flags['rawOutput'],
            rawOutput0: $flags['rawOutput0'],
            joinOutput: $flags['joinOutput'],
            ascii: $flags['ascii'],
            sortKeys: $flags['sortKeys'],
            color: $color,
            pretty: $pretty,
            tab: $tab,
            indent: $indent,
            exitStatus: $flags['exitStatus'],
            seq: $flags['seq'],
            stream: $flags['stream'],
            streamErrors: $flags['streamErrors'],
            unbuffered: $flags['unbuffered'],
            fromFile: $flags['fromFile'],
            debugDumpDisasm: $flags['debugDumpDisasm'],
        );
    }

    private function looksLikeOption(string $text): bool
    {
        return '' !== $text && '-' === $text[0] && \strlen($text) > 1;
    }

    /**
     * @throws UsageException
     */
    private function decodeOrRefuse(string $text, string $option): mixed
    {
        try {
            return $this->decoder->decodeOne($text);
        } catch (JsonSyntaxException) {
            throw new UsageException('jq: Invalid JSON text passed to ' . $option);
        }
    }

    /**
     * @return list<mixed>|string
     *
     * @throws UsageException
     */
    private function loadFile(string $which, string $name, string $file): array|string
    {
        try {
            $contents = FileReader::read($file);
        } catch (RuntimeException $runtimeException) {
            throw new UsageException(\sprintf('jq: Bad JSON in --%s %s %s: %s', $which, $name, $file, $runtimeException->getMessage()));
        }

        if ('rawfile' === $which) {
            return $contents;
        }

        try {
            return iterator_to_array($this->decoder->decodeAll($contents), false);
        } catch (JsonSyntaxException $jsonSyntaxException) {
            throw new UsageException(\sprintf('jq: Bad JSON in --%s %s %s: %s', $which, $name, $file, $jsonSyntaxException->getMessage()));
        }
    }
}
