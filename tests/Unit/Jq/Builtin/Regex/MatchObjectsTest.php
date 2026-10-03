<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\CodepointCursor;
use LTS\PhpXq\Jq\Builtin\Regex\MatchObjects;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MatchObjectsTest extends TestCase
{
    public function testMatchKeyOrderFollowsJq(): void
    {
        $groups = [['bar', 4], ['bar', 4]];
        $object = MatchObjects::match($groups, ['x'], new CodepointCursor('foo bar', true));

        self::assertSame(['offset', 'length', 'string', 'captures'], $object->keys());
        $captures = $object->get('captures');
        self::assertIsArray($captures);
        self::assertInstanceOf(JsonObject::class, $captures[0]);
        self::assertSame(['offset', 'length', 'string', 'name'], $captures[0]->keys());
    }

    public function testUnmatchedAndEmptyCapturesListStringBeforeLength(): void
    {
        $groups   = [['', 0], [null, -1], ['', 0]];
        $object   = MatchObjects::match($groups, ['a', 'b'], new CodepointCursor('', true));
        $captures = $object->get('captures');

        self::assertIsArray($captures);
        self::assertInstanceOf(JsonObject::class, $captures[0]);
        self::assertInstanceOf(JsonObject::class, $captures[1]);
        self::assertSame(['offset' => -1, 'string' => null, 'length' => 0, 'name' => 'a'], $captures[0]->toArray());
        self::assertSame(['offset' => 0, 'string' => '', 'length' => 0, 'name' => 'b'], $captures[1]->toArray());
    }

    public function testMissingTrailingGroupCountsAsUnmatched(): void
    {
        $object   = MatchObjects::match([['a', 0]], [null], new CodepointCursor('a', true));
        $captures = $object->get('captures');

        self::assertIsArray($captures);
        self::assertInstanceOf(JsonObject::class, $captures[0]);
        self::assertSame(-1, $captures[0]->get('offset'));
        self::assertNull($captures[0]->get('name'));
    }

    public function testOffsetsAndLengthsAreInCodepoints(): void
    {
        $subject = "\u{e9}\u{20ac}ab";
        $object  = MatchObjects::match([['ab', 5], ['b', 6]], [null], new CodepointCursor($subject, false));

        self::assertSame(2, $object->get('offset'));
        self::assertSame(2, $object->get('length'));
        $captures = $object->get('captures');
        self::assertIsArray($captures);
        self::assertInstanceOf(JsonObject::class, $captures[0]);
        self::assertSame(3, $captures[0]->get('offset'));
    }

    public function testNamedCaptureObject(): void
    {
        $groups = [['ab', 0], ['a', 0], [null, -1], ['b', 1]];
        $object = MatchObjects::named($groups, ['first', null, 'second']);

        self::assertSame(['first' => 'a', 'second' => 'b'], $object->toArray());
    }

    public function testNamedCaptureObjectKeepsTheLaterGroupForADuplicateName(): void
    {
        $object = MatchObjects::named([['ab', 0], ['a', 0], ['b', 1]], ['n', 'n']);

        self::assertSame(['n' => 'b'], $object->toArray());
    }

    public function testNamedCaptureObjectHasNullForUnmatchedGroups(): void
    {
        $object = MatchObjects::named([['', 0], [null, -1]], ['n']);

        self::assertSame(['n' => null], $object->toArray());
    }
}
