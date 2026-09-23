<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Sada contributorů deníku v pořadí registrace ({@see JournalContributor}).
 * Sbírá ji `JournalContributorLoader` z `journalContributors` v module.jsonc;
 * enginy ji dostávají volitelně — DS bez přispívajícího modulu (nebo engine
 * postavený bez sady) má prázdnou sadu a chová se přesně jako dřív.
 *
 * @implements \IteratorAggregate<int, JournalContributor>
 */
final class JournalContributorSet implements \IteratorAggregate, \Countable
{
    /** @var list<JournalContributor> */
    private readonly array $contributors;

    /**
     * @param iterable<JournalContributor> $contributors
     */
    public function __construct(iterable $contributors = [])
    {
        $list = [];
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof JournalContributor) {
                throw new \LogicException(
                    'JournalContributorSet accepts only JournalContributor instances, '
                    . get_debug_type($contributor) . ' given',
                );
            }
            $list[] = $contributor;
        }
        $this->contributors = $list;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->contributors === [];
    }

    public function count(): int
    {
        return count($this->contributors);
    }

    /** @return \ArrayIterator<int, JournalContributor> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->contributors);
    }
}
