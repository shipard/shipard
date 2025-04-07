<?php

namespace imports\erps\core\libs;

use \Shipard\Utils\Utils, \e10\json;


/**
 * class Import
 */
class ImportedDoc extends \Shipard\Base\Utility
{
  var $docType = '';
	var $docHead = [];
	var $docRows = [];

  var $replaceDocumentNdx = 0;

  /** @var \E10Doc\Core\TableHeads */
	var $tableHeads;
	/** @var \E10Doc\Core\TableRows */
	var $tableRows;

	public function init($docType)
	{
    $this->docType = $docType;
		$this->tableHeads = $this->app()->table('e10doc.core.heads');
		$this->tableRows = $this->app()->table('e10doc.core.rows');
  }

	public function setReplaceDocumentNdx ($ndx)
	{
		$this->replaceDocumentNdx = $ndx;
		return TRUE;
	}

	function createHead ($headRecData)
	{
		$this->docHead = ['docType' => $this->docType];
		$this->tableHeads->checkNewRec($this->docHead);

		$this->docHead ['docState'] = 4000;
		$this->docHead ['docStateMain'] = 2;

    foreach ($headRecData as $k => $v)
    {
      $this->docHead[$k] = $v;
    }

		$this->docRows = [];
	}

  function saveDocument_DELETE ()
	{
		$docNdx = $this->tableHeads->dbInsertRec ($this->docHead);
		$this->docHead['ndx'] = $docNdx;

		$f = $this->tableHeads->getTableForm ('edit', $docNdx);

		forEach ($this->docRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$this->tableHeads->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$this->tableHeads->checkDocumentState ($f->recData);
		$this->tableHeads->dbUpdateRec ($f->recData);
		$this->tableHeads->checkAfterSave2 ($f->recData);
		$this->tableHeads->docsLog($f->recData['ndx']);
	}

	function saveDoc ()
	{
		if ($this->replaceDocumentNdx === 0)
		{
			$docNdx = $this->tableHeads->dbInsertRec ($this->docHead);
			$this->docHead['ndx'] = $docNdx;
		}
		else
		{
			$dh = $this->tableHeads->loadItem ($this->replaceDocumentNdx);
			foreach ($this->docHead as $dhKey => $dhValue)
			{
				if ($dhKey === 'docState' || $dhKey === 'docStateMain')
					continue;
				$dh[$dhKey] = $dhValue;
			}
			$this->tableHeads->dbUpdateRec ($dh);
			$this->docHead = $this->tableHeads->loadItem ($this->replaceDocumentNdx);
			$docNdx = $this->replaceDocumentNdx;
			$this->db()->query ("DELETE FROM [e10doc_core_rows] WHERE [document] = %i", $docNdx);
		}

		$f = $this->tableHeads->getTableForm ('edit', $docNdx);

		forEach ($this->docRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($r, $f->recData);
		}

		$f->checkAfterSave();
		$this->tableHeads->checkDocumentState ($f->recData);
		$this->tableHeads->dbUpdateRec ($f->recData);
		$this->tableHeads->checkAfterSave2 ($f->recData);
		$this->tableHeads->docsLog($f->recData['ndx']);
	}
}

