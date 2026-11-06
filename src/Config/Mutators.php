<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

/**
 * Stores the list of manipulators grouped by phase.
 *
 * Хранит список манипуляторов, сгруппированных по фазам.
 */
final readonly class Mutators
{
    /**
     * @param string[] $afterInput
     * @param string[] $afterValidate
     * @param string[] $beforeOutput
     */
    public function __construct(
        public array $afterInput = [],
        public array $afterValidate = [],
        public array $beforeOutput = [],
    ) {
    }

    /**
     * Returns the list of aliases for the given phase.
     *
     * Возвращает список алиасов для указанной фазы.
     *
     * @return string[]
     */
    public function getForPhase(PhaseType $phase): array
    {
        return match ($phase) {
            PhaseType::AfterInput => $this->afterInput,
            PhaseType::AfterValidate => $this->afterValidate,
            PhaseType::BeforeOutput => $this->beforeOutput
        };
    }

    /**
     * All mutator aliases across the three phases, with duplicates.
     *
     * Все алиасы мутаторов по трём фазам, с повторами.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_values([...$this->afterInput, ...$this->afterValidate, ...$this->beforeOutput]);
    }

    public function isEmpty(): bool
    {
        return $this->afterInput === []
            && $this->afterValidate === []
            && $this->beforeOutput === [];
    }
}
