<?php
namespace e10\base\libs;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewPanel;
use \Shipard\Utils\Utils;


/**
 * Class ViewAttachmentsTechnical
 */
class ViewAttachmentsTechnical extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setPanels (TableView::sptQuery|TableView::sptReview);
	}

	public function selectRows ()
	{
		$q = [];
		array_push($q, 'SELECT atts.*');
		array_push($q, ' FROM [e10_attachments_files] AS atts');
		array_push($q, ' WHERE 1');

		// -- special queries
		$qv = $this->queryValues ();
		$othersDeleted = isset ($qv['others']['deleted']);
		$othersNonDeleted = isset ($qv['others']['nonDeleted']);
		if ($othersDeleted XOR $othersNonDeleted)
		{
			if ($othersDeleted)
				array_push($q, ' AND [deleted] = %i', 1);
			else
				array_push($q, ' AND [deleted] = %i', 0);
		}

		$fileSizeUnknown = isset ($qv['others']['fileSizeUnknown']);
		$fileSizeZero = isset ($qv['others']['fileSizeZero']);
		$fileNotFound = isset ($qv['others']['fileNotFound']);

		if ($fileNotFound)
			array_push($q, ' AND [fileSize] = %i', -1);
		if ($fileSizeZero)
			array_push($q, ' AND [fileSize] = %i', -2);
		if ($fileSizeUnknown)
			array_push($q, ' AND [fileSize] = %i', 0);

		array_push($q, ' ORDER BY fileSize DESC');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
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

		$listItem['icon'] = $attInfo['icon'];

		if ($item['deleted'])
			$listItem['class'] = 'e10-warning1';

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- others
		$chbxOthers = [
			'deleted' => ['title' => 'Smazané', 'id' => 'deleted'],
			'nonDeleted' => ['title' => 'Platné', 'id' => 'nonDeleted'],
			'fileSizeUnknown' => ['title' => 'Neznámá velikost souboru', 'id' => 'fileSizeUnknown'],
			'fileSizeZero' => ['title' => 'Nulová velikost souboru', 'id' => 'fileSizeZero'],
			'fileNotFound' => ['title' => 'Neexistující soubor', 'id' => 'fileNotFound'],
		];
		$paramsOthers = new \Shipard\UI\Core\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'others', 'style' => 'params', 'title' => ['text' => 'Ostatní', 'icon' => 'system/iconCogs'], 'params' => $paramsOthers];

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createPanelContentReview (TableViewPanel $panel)
	{
		$o = new \e10\base\libs\AttachmentsReview($this->app());
		$o->create();
		foreach ($o->content['body'] as $cp)
			$panel->addContent($cp);
	}
}
