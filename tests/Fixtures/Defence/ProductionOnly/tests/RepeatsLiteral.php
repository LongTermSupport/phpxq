<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\ProductionOnly\tests;

final class RepeatsLiteral
{
    public function first(): string
    {
        return 'repeated-value';
    }

    public function second(): string
    {
        return 'repeated-value';
    }

    public function third(): string
    {
        return 'repeated-value';
    }
}
