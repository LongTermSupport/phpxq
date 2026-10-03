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
        public bool $dontAutoCreate = false,
    ) {
    }

    /**
     * @param list<Candidate> $matches
     */
    public function withMatches(array $matches): self
    {
        return new self($matches, $this->services, $this->variables, $this->dontAutoCreate);
    }

    /**
     * @param list<Candidate> $values
     */
    public function withVariable(string $name, array $values): self
    {
        return new self($this->matches, $this->services, [...$this->variables, $name => $values], $this->dontAutoCreate);
    }

    /**
     * The reference's read-only flag: a traversal that finds no key yields no match instead of a null
     * placeholder that an assignment would later create.
     */
    public function withDontAutoCreate(bool $dontAutoCreate): self
    {
        if ($dontAutoCreate === $this->dontAutoCreate) {
            return $this;
        }

        return new self($this->matches, $this->services, $this->variables, $dontAutoCreate);
    }
}
