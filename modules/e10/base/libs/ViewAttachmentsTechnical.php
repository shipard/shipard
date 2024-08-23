<?php
namespace e10\base\libs;

use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;


/**
 * Class ViewAttachmentsTechnical
 */
class ViewAttachmentsTechnical extends TableView
{
	public function init ()
	{
		parent::init();
	}

	public function selectRows ()
	{
		$q = [];
		array_push($q, 'SELECT atts.*');
		array_push($q, ' FROM [e10_attachments_files] AS atts');
		array_push($q, ' WHERE 1');
		array_push($q, ' ORDER BY fileSize DESC');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		//$table = $this->table->app()->table ($item['tableid']);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item ['ndx'], 'class' => 'id'];
		$listItem ['tt'] = $item ['name'];

		$listItem ['i2'] = Utils::memf($item ['fileSize']);

		$listItem['t2'] = [];

		$attInfo = $this->table->attInfo($item);
		if (isset($attInfo['labels']))
			$listItem['t2'] = array_merge ($listItem['t2'], $attInfo['labels']);

		if ($item['created'])
			$listItem ['t2'][] = ['text' => Utils::datef ($item['created'], '%d, %T'), 'class' => 'label label-default'];

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}
