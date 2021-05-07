<?php

namespace swdev\icons\libs;

use \swdev\icons\libs\ImportCore, \e10\json;


/**
 * Class ImportFA5
 * @package swdev\icons\libs
 */
class ImportFA5 extends ImportCore
{
	public function init ()
	{
		$this->iconsMetadataFileName = __APP_DIR__.'/sc/icons/fa/5/metadata/icons.json';
		$this->thisIconsSetId = 'fa5';
		parent::init();
	}

	function icons()
	{
		return $this->iconsMetadata;
	}

	function importOne($icon)
	{
		if ($icon['styles'][0] === 'brands')
			return;

		//echo $icon['id']."\n";

		$i = [
			'id' => $icon['id'], 'name' => $icon['label'],
		];

		if (isset($icon['search']) && isset($icon['search']['terms']) && count($icon['search']['terms']))
			$i['keywords'] = $icon['search']['terms'];

		$this->saveIcon($i);
	}
}


