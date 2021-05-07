<?php

namespace e10\base;
use \E10\DbTable;
use E10\TableForm;


/**
 * Class TableAttachmentsMetaData
 * @package e10\base
 */
class TableAttachmentsMetaData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.attachmentsMetaData', 'e10_attachments_metaData', 'Data v Přílohách dokumentů');
	}
}


/**
 * Class FormAttachmentsMetaData
 * @package e10\base
 */
class FormAttachmentsMetaData extends TableForm
{
	var $metaData = [];

	public function renderForm ()
	{
		$this->loadMetaData();

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addColumnInput('ndx', self::coHidden);
			$this->addContent($this->metaData['content']);
		$this->closeForm ();
	}

	function loadMetaData()
	{
		$mdConfig = $this->app()->cfgItem ('e10.att.metaDataTypes');

		$mdType = isset($mdConfig[$this->recData['metaDataType']]) ? $mdConfig[$this->recData['metaDataType']] : NULL;
		if (!$mdType)
			return;

		$item = ['mdType' => $mdType, ];

		if ($this->recData['metaDataType'] === 1)
		{ // -- text content
			$item['content'][] = ['type' => 'text', 'subtype' => 'code', 'text' => $this->recData['data']];
		}
		elseif ($this->recData['metaDataType'] === 2)
		{ // -- exif
			$d = json_decode($this->recData['data'], TRUE);
			if (!$d)
			{
				$item['content'][] = ['type' => 'text', 'subtype' => 'auto', 'text' => 'Vadná EXIF data'];
				$this->metaData = $item;
				return;
			}

			foreach ($d as $exifPartNdx => $exifPartData)
			{
				$t = [];
				foreach ($exifPartData as $key => $value)
					$t[] = ['k' => $key, 'v' => $value];

				$h = ['k' => 'Parametr', 'v' => 'Hodnota'];
				$item['content'][] = ['type' => 'table', 'table' => $t, 'header' => $h];
			}
		}



		$this->metaData = $item;
	}
}
