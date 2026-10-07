<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\OtherMailQuery;

/**
 * Sdílená podmínka čekající ostatní pošty (tasks/mail-other-attention.md
 * D4, D5): tvar, na kterém stojí feed (karty Ostatní / K vyřízení)
 * i Archivovat vše — jedno místo pravdy.
 */
final class OtherMailQueryTest extends TestCase
{
    public function testPendingWhereShape(): void
    {
        $sql = OtherMailQuery::pendingWhere();

        $this->assertStringContainsString('`m`.`analysis_state` = 30', $sql);
        $this->assertStringContainsString('`m`.`docState` = 10', $sql);
        $this->assertStringContainsString("`m`.`primary_type` = 'other'", $sql);
        $this->assertStringContainsString('COALESCE((', $sql);
        $this->assertStringContainsString('`a`.`canonical_json` IS NOT NULL AND `a`.`resolution` IS NULL', $sql);
        $this->assertStringContainsString('), 0) = 0', $sql);
        $this->assertStringNotContainsString('%', $sql, 'fragment bez Dibi placeholderů');
    }

    public function testInformationalWhereAddsAttentionFilter(): void
    {
        $sql = OtherMailQuery::informationalWhere();

        $this->assertStringStartsWith(OtherMailQuery::pendingWhere(), $sql);
        $this->assertStringEndsWith("`m`.`attention` IN ('info', 'promo')", $sql);
        $this->assertStringNotContainsString("'action'", $sql);
    }
}
