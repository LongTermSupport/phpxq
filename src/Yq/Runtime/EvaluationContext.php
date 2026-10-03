<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

/**
 * The state an evaluation step runs in: the current matches, bound variables and shared services.
 * Immutable; the `with*` methods return modified copies.
 *
 * yq semantics: an operator receives ALL current matches at once (not one at a time), so operators
 * such as `collect`, `sort`, `add`, `first` and `reduce` can see the whole list.
 */
final readonly class EvaluationContext
{
    /**
     * @param list<Candidate>                $matches
     * @param array<string, list<Candidate>> $variables
     */
    public function __construct(
        public array $matches,
        public RuntimeServices $services,
        public array $variables = [],
    ) {
    }

    /**
     * @param list<Candidate> $matches
     */
    public function withMatches(array $matches): self
    {
        return new self($matches, $this->services, $this->variables);
    }

    /**
     * @param list<Candidate> $values
     */
    public function withVariable(string $name, array $values): self
    {
        return new self($this->matches, $this->services, [...$this->variables, $name => $values]);
    }
}
