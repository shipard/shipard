<?php

namespace swdev\dm\dc;

use e10\utils, e10\json;


/**
 * Class DMEnumTrData
 * @package swdev\dm\dc
 */
class DMEnumTrData extends \e10\DocumentCard
{
	function loadData()
	{
		$allLanguages = $this->app()->cfgItem ('swdev.tr.lang.langs', []);


		$q[] = 'SELECT * FROM [swdev_dm_enumsTrData]';
		array_push($q, ' WHERE [enum] = %i', $this->recData['ndx']);
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$lang = $allLanguages[$r['lang']];
			$title = ['text' => $lang['flag'].' '.$lang['name'], 'class' => 'h2', 'suffix' => $r['checksum']];
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
				'type' => 'text', 'subtype' => 'code', 'text' => $r['data']]);
		}
	}

	public function createContent ()
	{
		$this->loadData();
	}
}
