<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocStateConfig;

class DocStateConfigTest extends TestCase
{
    private function archiveStates(): array
    {
        return [
            '10' => [
                'stateName' => 'Koncept', 'actionName' => 'Uložit jako koncept',
                'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active',
                'goto' => [40, 70, 90],
            ],
            '80' => [
                'stateName' => 'V opravě', 'actionName' => 'Opravit',
                'stateStyle' => 'edit', 'mainState' => 2, 'viewGroup' => 'active',
                'goto' => [40, 70, 90],
            ],
            '40' => [
                'stateName' => 'V pořádku', 'actionName' => 'V pořádku',
                'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active',
                'readOnly' => 1, 'enablePrint' => 1, 'goto' => [80, 70, 90],
            ],
            '70' => [
                'stateName' => 'V archívu', 'actionName' => 'Ukončit platnost',
                'stateStyle' => 'archive', 'mainState' => 4, 'viewGroup' => 'archive',
                'readOnly' => 1, 'goto' => [80],
            ],
            '90' => [
                'stateName' => 'Smazáno', 'actionName' => 'Smazat',
                'stateStyle' => 'trash', 'mainState' => 5, 'viewGroup' => 'trash',
                'readOnly' => 1, 'goto' => [80],
            ],
        ];
    }

    public function testGetState(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $state = $cfg->getState(10);
        $this->assertNotNull($state);
        $this->assertSame('Koncept', $state['stateName']);
        $this->assertSame('concept', $state['stateStyle']);
    }

    public function testGetStateUnknownReturnsNull(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertNull($cfg->getState(999));
    }

    public function testGetMainState(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertSame(1, $cfg->getMainState(10));
        $this->assertSame(2, $cfg->getMainState(80));
        $this->assertSame(3, $cfg->getMainState(40));
        $this->assertSame(4, $cfg->getMainState(70));
        $this->assertSame(5, $cfg->getMainState(90));
    }

    public function testGetMainStateUnknownDefaultsToOne(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertSame(1, $cfg->getMainState(999));
    }

    public function testIsReadOnly(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertFalse($cfg->isReadOnly(10));  // Koncept
        $this->assertFalse($cfg->isReadOnly(80));  // V opravě
        $this->assertTrue($cfg->isReadOnly(40));   // V pořádku
        $this->assertTrue($cfg->isReadOnly(70));   // V archívu
        $this->assertTrue($cfg->isReadOnly(90));   // Smazáno
    }

    public function testIsReadOnlyUnknownDefaultsFalse(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertFalse($cfg->isReadOnly(999));
    }

    public function testIsTransitionAllowedFromKoncept(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertTrue($cfg->isTransitionAllowed(10, 40));  // → V pořádku
        $this->assertTrue($cfg->isTransitionAllowed(10, 70));  // → V archívu
        $this->assertTrue($cfg->isTransitionAllowed(10, 90));  // → Smazáno
        $this->assertFalse($cfg->isTransitionAllowed(10, 80)); // Koncept cannot go directly to V opravě
    }

    public function testIsTransitionAllowedFromVPoradku(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertTrue($cfg->isTransitionAllowed(40, 80));  // → V opravě
        $this->assertTrue($cfg->isTransitionAllowed(40, 70));  // → V archívu
        $this->assertTrue($cfg->isTransitionAllowed(40, 90));  // → Smazáno
        $this->assertFalse($cfg->isTransitionAllowed(40, 10)); // cannot go back to Koncept
    }

    public function testIsTransitionAllowedFromArchiv(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertTrue($cfg->isTransitionAllowed(70, 80));   // → V opravě (only option)
        $this->assertFalse($cfg->isTransitionAllowed(70, 40));  // cannot go to V pořádku directly
        $this->assertFalse($cfg->isTransitionAllowed(70, 90));  // cannot go to Smazáno
    }

    public function testGetViewGroupStatesActive(): void
    {
        $cfg    = DocStateConfig::fromCfgItem($this->archiveStates());
        $active = $cfg->getViewGroupStates('active');
        sort($active);
        $this->assertSame([10, 40, 80], $active);
    }

    public function testGetViewGroupStatesArchive(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertSame([70], $cfg->getViewGroupStates('archive'));
    }

    public function testGetViewGroupStatesTrash(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertSame([90], $cfg->getViewGroupStates('trash'));
    }

    public function testGetViewGroupStatesUnknown(): void
    {
        $cfg = DocStateConfig::fromCfgItem($this->archiveStates());
        $this->assertSame([], $cfg->getViewGroupStates('unknown'));
    }

    public function testGetAvailableTransitionsFromKoncept(): void
    {
        $cfg         = DocStateConfig::fromCfgItem($this->archiveStates());
        $transitions = $cfg->getAvailableTransitions(10);

        $this->assertCount(3, $transitions);

        $states = array_column($transitions, 'state');
        $this->assertContains(40, $states);
        $this->assertContains(70, $states);
        $this->assertContains(90, $states);
    }

    public function testGetAvailableTransitionsStructure(): void
    {
        $cfg         = DocStateConfig::fromCfgItem($this->archiveStates());
        $transitions = $cfg->getAvailableTransitions(10);

        $this->assertArrayHasKey('state', $transitions[0]);
        $this->assertArrayHasKey('stateName', $transitions[0]);
        $this->assertArrayHasKey('actionName', $transitions[0]);
        $this->assertArrayHasKey('stateStyle', $transitions[0]);
        $this->assertArrayHasKey('close_form', $transitions[0]);
        $this->assertArrayHasKey('mobileKebab', $transitions[0]);
    }

    public function testMobileKebabFlagDefaultsFalseAndReflectsState(): void
    {
        $states = [
            '10' => [
                'stateName' => 'Nový', 'actionName' => 'Vrátit jako nový',
                'stateStyle' => 'concept', 'goto' => [20, 30],
            ],
            '20' => [
                'stateName' => 'V práci', 'actionName' => 'V práci',
                'stateStyle' => 'confirmed', 'goto' => [10],
            ],
            '30' => [
                'stateName' => 'Pozastaveno', 'actionName' => 'Pozastavit',
                'stateStyle' => 'edit', 'mobileKebab' => 1, 'goto' => [10],
            ],
        ];
        $cfg = DocStateConfig::fromCfgItem($states);
        $transitions = $cfg->getAvailableTransitions(10);

        $byState = array_column($transitions, null, 'state');
        // Bez příznaku → false.
        $this->assertFalse($byState[20]['mobileKebab']);
        // S "mobileKebab": 1 → true.
        $this->assertTrue($byState[30]['mobileKebab']);
    }

    public function testGetAvailableTransitionsFromArchiv(): void
    {
        $cfg         = DocStateConfig::fromCfgItem($this->archiveStates());
        $transitions = $cfg->getAvailableTransitions(70);

        $this->assertCount(1, $transitions);
        $this->assertSame(80, $transitions[0]['state']);
        $this->assertSame('Opravit', $transitions[0]['actionName']);
    }

    public function testFromNullCfgItemIsPermissive(): void
    {
        $cfg = DocStateConfig::fromCfgItem(null);

        // Defaults should be safe (no states → no transitions, no readOnly)
        $this->assertNull($cfg->getState(10));
        $this->assertSame(1, $cfg->getMainState(10));
        $this->assertFalse($cfg->isReadOnly(40));
        $this->assertFalse($cfg->isTransitionAllowed(10, 40));
        $this->assertSame([], $cfg->getViewGroupStates('active'));
        $this->assertSame([], $cfg->getAvailableTransitions(10));
    }

    public function testShowsDetailBadgeDefaultsTrueAndHonoursFlag(): void
    {
        $states = $this->archiveStates();
        $states['40']['detailBadge'] = false;
        $cfg = DocStateConfig::fromCfgItem($states);

        $this->assertTrue($cfg->showsDetailBadge(10), 'bez klíče = štítek');
        $this->assertFalse($cfg->showsDetailBadge(40), 'detailBadge: false skryje (#136 D20)');
        $this->assertTrue($cfg->showsDetailBadge(99), 'neznámý stav = štítek');
    }
}
