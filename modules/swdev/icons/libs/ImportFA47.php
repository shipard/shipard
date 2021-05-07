<?php

namespace swdev\icons\libs;

use \swdev\icons\libs\ImportCore, \e10\Utility, \e10\json;


/**
 * Class ImportFA47
 * @package swdev\icons\libs
 */
class ImportFA47 extends ImportCore
{
	public function init ()
	{
		$this->iconsMetadataFileName = __APP_DIR__.'/sc/icons/fa/4.7/metadata/icons.json';
		$this->thisIconsSetId = 'fa47';
		parent::init();
	}

	function icons()
	{
		return $this->iconsMetadata['icons'];
	}

	function importOne($icon)
	{
		//echo $icon['id']."\n";

		$i = [
			'id' => $icon['id'], 'name' => $icon['name'],

		];

		if (isset($icon['filter']) && count($icon['filter']))
			$i['keywords'] = $icon['filter'];

		$this->saveIcon($i);
	}
}


