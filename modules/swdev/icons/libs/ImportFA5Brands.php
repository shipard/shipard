<?php

namespace swdev\icons\libs;

use \swdev\icons\libs\ImportCore, \e10\json;


/**
 * Class ImportFA5Brands
 * @package swdev\icons\libs
 */
class ImportFA5Brands extends ImportCore
{
	public function init ()
	{
		$this->iconsMetadataFileName = __APP_DIR__.'/sc/icons/fa/5/metadata/icons.json';
		$this->thisIconsSetId = 'fa5-brands';
		parent::init();
	}

	function icons()
	{
		return $this->iconsMetadata;
	}

	function importOne($icon)
	{
		if ($icon['styles'][0] !== 'brands')
			return;

		$i = [
			'id' => $icon['id'], 'name' => $icon['label'],
		];

		if (isset($icon['search']) && isset($icon['search']['terms']) && count($icon['search']['terms']))
			$i['keywords'] = $icon['search']['terms'];

		$this->saveIcon($i);
	}
}


