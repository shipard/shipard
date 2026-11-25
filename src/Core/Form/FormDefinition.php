<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

class FormDefinition
{
    /**
     * @param FormTab[] $tabs
     * @param list<array{label: string, value: string}> $liveSummary Živé
     *        součty nad obsahem formuláře (pruh mezi tab-barem a validačním
     *        bannerem). Na rozdíl od `$headerInfo` (uložený stav, klient ho
     *        drží z loadu) se sestavují z aktuálních `$data` při každém
     *        buildFormDefinition — load i recalculate — takže odrážejí
     *        neuložený stav. Volitelné a generické; první uživatel
     *        `DocRowsForm` (Základ · DPH · Celkem řádku dokladu, #71).
     *        Prázdné = klient nic nerenderuje.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $title,
        public readonly string $titleNew,
        public readonly array $tabs,
        public ?array $docStates = null,
        public ?FormHeaderInfo $headerInfo = null,
        public array $liveSummary = [],
    ) {}

    public function withDocStates(array $docStatesInfo): static
    {
        $clone = clone $this;
        $clone->docStates = $docStatesInfo;
        return $clone;
    }

    public function withHeaderInfo(?FormHeaderInfo $headerInfo): static
    {
        $clone = clone $this;
        $clone->headerInfo = $headerInfo;
        return $clone;
    }

    /**
     * Tatáž definice s dalšími záložkami na konci (záložky z modulů,
     * #172 D17). Bez záložek vrací sebe.
     *
     * @param list<FormTab> $tabs
     */
    public function withAppendedTabs(array $tabs): self
    {
        if ($tabs === []) {
            return $this;
        }
        return new self(
            $this->table,
            $this->title,
            $this->titleNew,
            [...$this->tabs, ...$tabs],
            $this->docStates,
            $this->headerInfo,
            $this->liveSummary,
        );
    }

    public function toArray(): array
    {
        $result = [
            'table'       => $this->table,
            'title'       => $this->title,
            'title_new'   => $this->titleNew,
            'tabs'        => array_map(
                fn(FormTab $tab) => $tab->toArray(),
                $this->tabs,
            ),
            'header_info' => $this->headerInfo?->toArray(),
        ];

        if ($this->docStates !== null) {
            $result['doc_states'] = $this->docStates;
        }
        // Jen když neprázdné — výstup ostatních formulářů se nemění.
        if ($this->liveSummary !== []) {
            $result['live_summary'] = $this->liveSummary;
        }

        return $result;
    }
}
