<?php

declare(strict_types=1);

namespace LTS\PhpXq\PhpQaCi;

use Attribute;

/**
 * GENERATED & MANAGED BY php-qa-ci — DO NOT EDIT.
 *
 * This file is (re)generated into your project on every `composer install`
 * / `composer update` by php-qa-ci's ManagedSourceDeployPlugin, and is
 * drift-checked by the QA pipeline. Any hand edit will be reverted on the
 * next install/update and will fail QA in the meantime. To change it, change
 * php-qa-ci's ManagedSourceGenerator, not this file.
 *
 * Marks a class as factory-sealed: it may be constructed (`new X(...)`) only
 * by the single authorised factory named here. PHP has no friend/
 * package-private visibility, so this sole-producer constraint is expressed
 * via this attribute and enforced statically.
 *
 * Enforced by {@see \LTS\PHPQA\PHPStan\Rules\FactorySealedRule} (configured
 * with this attribute's FQCN via its `sealingAttributes` parameter), which
 * flags `new <sealed>(...)` outside the authorised factory (tests are exempt).
 *
 * It lives in your project's own (production) namespace — not php-qa-ci's —
 * because production code annotates with it and must never `use` a
 * `require-dev` package.
 *
 * @internal this managed artefact is not part of your package's public
 *           API surface, so a type:library consumer's API-surface
 *           classification rule passes over it on that basis
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FactorySealedBy
{
    /**
     * @param class-string $factory the sole authorised producer of the sealed type
     */
    public function __construct(public string $factory)
    {
    }
}
