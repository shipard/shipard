<?php

namespace swdev\icons\libs;

use \swdev\icons\libs\ImportCore, \e10\json;


/**
 * Class ImportWorldFlags
 * @package swdev\icons\libs
 */
class ImportWorldFlags extends ImportCore
{
	public function init ()
	{
		$this->iconsMetadataFileName = __APP_DIR__.'/sc/icons/flags/metadata/countries.json';
		$this->thisIconsSetId = 'wf';
		parent::init();
	}

	function icons()
	{
		return $this->iconsMetadata['countries'];
	}

	function importOne($icon)
	{
		$i = [
			'id' => $icon['i'], 'name' => $icon['ec'],
		];

		$this->saveIcon($i);
	}
}


