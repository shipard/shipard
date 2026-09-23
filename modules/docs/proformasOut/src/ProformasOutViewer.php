<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\ProformasOut;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Viewer Zálohových faktur vydaných — `docs_core_heads` s pevným filtrem
 * `doc_type = 'invpo'` (#79 D1). Sloupce, detail, řady dole i defaulty
 * nového záznamu dědí z DocsHeadsViewer.
 */
class ProformasOutViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'invpo';
}
