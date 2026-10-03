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
    public function testConsumeClearsTheRecord(): void
    {
        $comment = new ScanComment(1, 2, 3, 4, 5, 6, 7, 8, '# h', '', '');
        $comment->consume();

        self::assertSame('', $comment->head);
        self::assertSame(0, $comment->startColumn);
        self::assertSame(PHP_INT_MIN, $comment->endIndex);
    }
}
