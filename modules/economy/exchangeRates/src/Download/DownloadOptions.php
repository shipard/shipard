<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Volby běhu stahovače (#121 task B §5): `from` / `to` přepíšou okno
 * a přepnou do ručního módu (bez limitu dotazů, bez pauzy a bez stavu
 * pokusů), `type` omezí běh na jeden typ.
 */
final readonly class DownloadOptions
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?string $type = null,
    ) {
    }

    public function manual(): bool
    {
        return $this->from !== null || $this->to !== null;
    }
}
