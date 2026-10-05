<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Yaml\Token\ScanComment;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ScanCommentTest extends TestCase
{
    public function testItCarriesWhatTheScannerRecorded(): void
    {
        $comment = new ScanComment(1, 2, 3, 4, 5, 6, '# h', '', '');

        self::assertSame('# h', $comment->head);
        self::assertSame(5, $comment->startColumn);
        self::assertSame(6, $comment->endIndex);
    }

    public function testCloneKeepsTheValues(): void
    {
        $comment = new ScanComment(1, 2, 3, 4, 5, 6, '', '# l', '');

        self::assertEquals($comment, clone $comment);
    }
}
