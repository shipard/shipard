<?php

namespace lib\wkf;


use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages;


/**
 * Class ViewerDocumentsAll
 * @package lib\wkf
 */
class ViewerDocumentsAll extends \lib\wkf\ViewerDocumentsCore
{
	public function init ()
	{
		$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		parent::init();
	}

	public function qryMessageTypes (&$q, $selectPart)
	{
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = 'active';

		array_push ($q, ' AND (');

		array_push ($q, '( 1 ');

		{
			$fts = $this->fullTextSearch ();
			if ($fts !== '')
			{
				if ($mqId === 'active')
					array_push($q, ' AND (docs.[docStateMain] IN %in)', [0, 1, 2, 5]);
				elseif ($mqId === 'archive')
					array_push($q, ' AND (docs.[docStateMain] = %i)', 5);
				elseif ($mqId === 'trash')
					array_push($q, ' AND (docs.[docStateMain] = %i)', 4);
			}
			else
			{
				if ($mqId === 'active')
					array_push($q, ' AND (docs.[docStateMain] = %i OR docs.[docState] = 8000)', 2);
				elseif ($mqId === 'archive')
					array_push($q, ' AND (docs.[docStateMain] = %i)', 5);
				elseif ($mqId === 'trash')
					array_push($q, ' AND (docs.[docStateMain] = %i)', 4);
			}
		}

		array_push ($q, ')');

		if ($mqId === 'active')
		{
			array_push($q, ' OR (',

				' docs.author = %i', $this->thisUserId, ' AND docs.docStateMain = 0',
				')');
		}
		array_push ($q, ')');
	}
}

