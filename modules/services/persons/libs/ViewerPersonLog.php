<?php

namespace services\persons\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;


class ViewerPersonLog extends TableView
{
	var $personsIds = [];
	var $registers;
  var $personNdx = 0;

	public function init()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('person'))
			$this->personNdx = intval($this->queryParam ('person'));

    parent::init();
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
	}

	public function renderRow ($item)
	{
    $lr = new \services\persons\libs\LogRecord($this->app());
    $lr->parse($item);

    //$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $lr->parsed['title'];
    $listItem ['i1'] = Utils::datef($item['created'], '%x');
    $listItem ['i2'] = ['text' => Utils::nf($item['timeLen']), 'suffix' => 'ms'];
    //$listItem ['t3'] = "`".$item['logData']."`";

		if ($item['logData'] === '' || $item['logData'] === NULL)
			$listItem ['t2'] = 'Žádné změny...';
	
		if (isset($lr->parsed['content']) && count($lr->parsed['content']))
			$listItem ['content'] = $lr->parsed['content'];
		
    return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT log.* ';
		array_push ($q, ' FROM [services_persons_log] AS log');
		array_push ($q, ' WHERE 1');

    if ($this->personNdx)
      array_push ($q, ' AND [recId] = %i', $this->personNdx);

		// -- fulltext
		if ($fts != '')
		{
		}

		array_push ($q, ' ORDER BY [ndx] DESC');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}
