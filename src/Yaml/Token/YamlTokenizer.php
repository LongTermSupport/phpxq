<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

use Generator;
use LTS\PhpXq\Yaml\NodeStyleEnum;

/**
 * Turns YAML text into the public {@see Token} stream by running the same {@see Scanner} the parser
 * uses, so the two can never disagree. Comments are interleaved at their source position as Comment
 * tokens; a comment block that spans several lines is one token whose value joins the lines with a
 * newline (a blank line between two lines shows as an empty line).
 *
 * The synthetic block tokens (BlockMappingStart, BlockEnd and so on) carry the position libyaml gives
 * them, which for BlockEnd can be the start of the comment that trails the block.
 */
final class YamlTokenizer implements YamlTokenizerInterface
{
    /**
     * @return Generator<int, Token>
     */
    public function tokenize(string $yaml): iterable
    {
        $scanner = new Scanner($yaml);
        $scanner->logComments();

        /** @var list<array{int, Token}> $items */
        $items = [];
        while (true) {
            $scan    = $scanner->peek();
            $items[] = [$scan->startIndex * 4, $this->convert($scan)];
            $scanner->skip();
            if (ScanToken::STREAM_END === $scan->type) {
                break;
            }
        }

        foreach ($scanner->loggedComments() as $comment) {
            $text = $comment->head;
            if ('' === $text) {
                $text = $comment->line;
            }

            if ('' === $text) {
                $text = $comment->foot;
            }

            if ('' === $text) {
                continue;
            }

            // a line comment records a 0-based column, head and foot blocks a 1-based one
            $column  = '' !== $comment->line ? $comment->startColumn + 1 : $comment->startColumn;
            $items[] = [$comment->startIndex * 4 + 1, new Token(TokenTypeEnum::Comment, $text, $comment->startLine + 1, $column)];
        }

        usort($items, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($items as [, $token]) {
            yield $token;
        }
    }

    private function convert(ScanToken $t): Token
    {
        $line   = $t->startLine   + 1;
        $column = $t->startColumn + 1;

        return match ($t->type) {
            ScanToken::STREAM_START         => new Token(TokenTypeEnum::StreamStart, '', $line, $column),
            ScanToken::STREAM_END           => new Token(TokenTypeEnum::StreamEnd, '', $line, $column),
            ScanToken::VERSION_DIRECTIVE    => new Token(TokenTypeEnum::Directive, 'YAML ' . $t->value, $line, $column),
            ScanToken::TAG_DIRECTIVE        => new Token(TokenTypeEnum::Directive, 'TAG ' . $t->value . ' ' . $t->suffix, $line, $column),
            ScanToken::DOCUMENT_START       => new Token(TokenTypeEnum::DocumentStart, '', $line, $column),
            ScanToken::DOCUMENT_END         => new Token(TokenTypeEnum::DocumentEnd, '', $line, $column),
            ScanToken::BLOCK_SEQUENCE_START => new Token(TokenTypeEnum::BlockSequenceStart, '', $line, $column),
            ScanToken::BLOCK_MAPPING_START  => new Token(TokenTypeEnum::BlockMappingStart, '', $line, $column),
            ScanToken::BLOCK_END            => new Token(TokenTypeEnum::BlockEnd, '', $line, $column),
            ScanToken::FLOW_SEQUENCE_START  => new Token(TokenTypeEnum::FlowSequenceStart, '', $line, $column),
            ScanToken::FLOW_SEQUENCE_END    => new Token(TokenTypeEnum::FlowSequenceEnd, '', $line, $column),
            ScanToken::FLOW_MAPPING_START   => new Token(TokenTypeEnum::FlowMappingStart, '', $line, $column),
            ScanToken::FLOW_MAPPING_END     => new Token(TokenTypeEnum::FlowMappingEnd, '', $line, $column),
            ScanToken::BLOCK_ENTRY          => new Token(TokenTypeEnum::BlockEntry, '', $line, $column),
            ScanToken::FLOW_ENTRY           => new Token(TokenTypeEnum::FlowEntry, '', $line, $column),
            ScanToken::KEY                  => new Token(TokenTypeEnum::Key, '', $line, $column),
            ScanToken::VALUE                => new Token(TokenTypeEnum::Value, '', $line, $column),
            ScanToken::ALIAS                => new Token(TokenTypeEnum::Alias, $t->value, $line, $column),
            ScanToken::ANCHOR               => new Token(TokenTypeEnum::Anchor, $t->value, $line, $column),
            ScanToken::TAG                  => new Token(TokenTypeEnum::Tag, $this->tagText($t), $line, $column),
            default                         => new Token(
                TokenTypeEnum::Scalar,
                $t->value,
                $line,
                $column,
                match ($t->style) {
                    ScanToken::SINGLE  => NodeStyleEnum::SingleQuoted,
                    ScanToken::DOUBLE  => NodeStyleEnum::DoubleQuoted,
                    ScanToken::LITERAL => NodeStyleEnum::Literal,
                    ScanToken::FOLDED  => NodeStyleEnum::Folded,
                    default            => NodeStyleEnum::Default,
                },
            ),
        };
    }

    private function tagText(ScanToken $t): string
    {
        if ('' === $t->value) {
            return '!' === $t->suffix ? '!' : '!<' . $t->suffix . '>';
        }

        return $t->value . $t->suffix;
    }
}
