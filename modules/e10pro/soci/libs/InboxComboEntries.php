<?php

namespace e10pro\soci\libs;

/**
 * class InboxComboEntries
 */
class InboxComboEntries extends \wkf\core\viewers\InboxCombo
{
	public function setShowSections()
	{
    $entryKindNdx = intval($this->queryParam('entryKind'));
    $entryKind = $this->app()->cfgItem('e10pro.soci.entriesKinds.'.$entryKindNdx, NULL);

    if ($entryKind && isset($entryKind['inboxSection']))
      $this->showSections[] = intval($entryKind['inboxSection']);
	}
}

