<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\ProformasIn;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Viewer Zálohových faktur přijatých — `docs_core_heads` s pevným filtrem
 * `doc_type = 'invpi'` (#106 D1). Sloupce, detail, řady dole i defaulty
 * nového záznamu dědí z DocsHeadsViewer.
 */
class ProformasInViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'invpi';
}
