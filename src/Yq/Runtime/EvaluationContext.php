<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;

/**
 * The state an evaluation step runs in: the current matches, bound variables and shared services.
 * Immutable; the `with*` methods return modified copies.
 *
 * yq semantics: an operator receives ALL current matches at once (not one at a time), so operators
 * such as `collect`, `sort`, `add`, `first` and `reduce` can see the whole list.
 *
 * @internal
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
        public ?Node $replacedNode = null,
    ) {
    }

    public function withMatches(Candidate ...$matches): self
    {
        return new self(array_values($matches), $this->services, $this->variables, $this->dontAutoCreate, $this->replacedNode);
    }

    public function withVariable(string $name, Candidate ...$values): self
    {
        return new self($this->matches, $this->services, [...$this->variables, $name => array_values($values)], $this->dontAutoCreate, $this->replacedNode);
    }

    /**
     * The node an enclosing update (`|=`) is about to replace with the value of this evaluation. Arithmetic
     * on that very node may build its result out of the node's own children instead of copies, because
     * the result takes the node's place: copies would orphan the matches an outer `..` still holds.
     */
    public function withReplacedNode(Node $replacedNode): self
    {
        return new self($this->matches, $this->services, $this->variables, $this->dontAutoCreate, $replacedNode);
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

        return new self($this->matches, $this->services, $this->variables, $dontAutoCreate, $this->replacedNode);
    }
}
