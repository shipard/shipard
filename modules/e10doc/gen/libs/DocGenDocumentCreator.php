<?php

namespace e10doc\gen\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class DocGenDocumentCreator
 */
class DocGenDocumentCreator extends Utility
{
  //var $gens = NULL;
  var $srcDocNdx = 0;
  var $srcDocRecData = NULL;
  var $docGenCfgNdx = 0;
  var $docGenCfg = NULL;

  /** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;
  /** @var \e10doc\core\TableRows */
	var $tableDocsRows;


	var $docHead = NULL;
	var $docRows = NULL;
  var $newDocNdx = 0;



  public function init()
  {
    $this->tableDocsHeads = $this->app->table ('e10doc.core.heads');
    $this->tableDocsRows = $this->app->table ('e10doc.core.rows');
  }

	protected function createDocumentHead ()
	{
		$this->docHead = ['docType' => 'invno'];
		$this->tableDocsHeads->checkNewRec($this->docHead);

//		$this->docHead ['docState'] = 4000;
//		$this->docHead ['docStateMain'] = 2;

		$this->docHead ['docState'] = 1000;
		$this->docHead ['docStateMain'] = 0;

		$this->docHead ['docType'] = $this->docGenCfg['dstDocType'];


		$dbCounters = $this->app()->cfgItem ('e10.docs.dbCounters.' . $this->docGenCfg['dstDocType'], ['1' => []]);
		$activeDbCounter = key($dbCounters);


		$this->docHead ['dbCounter'] = $activeDbCounter;

    $this->docHead ['person'] = $this->srcDocRecData['person'];
    $this->docHead ['otherAddress1'] = $this->srcDocRecData['otherAddress1'];

    $useTransport = 0;
    if ($this->srcDocRecData['transport'] !== 0)
      $useTransport = 1;

    if ($useTransport)
    {
      $this->docHead ['transport'] = $this->srcDocRecData['transport'];
      $this->docHead ['transportVLP'] = $this->srcDocRecData['transportVLP'];
      $this->docHead ['transportVWeight'] = $this->srcDocRecData['transportVWeight'];
      $this->docHead ['transportPersonDriver'] = $this->srcDocRecData['transportPersonDriver'];
    }

    $this->docHead ['currency'] = $this->srcDocRecData['currency'];

		$this->docHead ['title'] = $this->srcDocRecData['title'];
		$this->docHead ['dateIssue'] = Utils::today();
		$this->docHead ['dateTax'] = Utils::today();
		$this->docHead ['dateDue'] = Utils::today();
		$this->docHead ['dateAccounting'] = Utils::today();

		$this->docHead ['paymentMethod'] = '0';
		$this->docHead ['roundMethod'] = intval($this->app->cfgItem ('options.e10doc-sale.roundInvoice', 0));

		$this->docHead ['author'] = $this->app()->userNdx();

		$this->docRows = [];
	}

  protected function createDocumentRows ()
  {
		$q = [];
		array_push($q, 'SELECT [rows].*, items.fullName AS itemFullName, items.id AS itemID, items.manufacturerId AS itemManufacturerId, items.description AS itemDecription');
		array_push($q, ' FROM [e10doc_core_rows] as [rows]');
		array_push($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push($q, ' WHERE [document] = %i', $this->srcDocNdx);
		array_push($q, ' AND rowType != %i', 1);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $operation = 0;

      if ($this->srcDocRecData['docType'] === 'stockout' && $this->docHead ['docType'] === 'invno')
        $operation = 1010002;

      $newRow = [
        'item' => $r['item'],
        'quantity' => $r['quantity'],
        'unit' => $r['unit'],
        'priceItem' => $r['priceItem'],
        'text' => $r['text'],
        'operation' => $operation,
      ];

      $this->docRows[] = $newRow;
    }
  }

  public function setParams($docGenCfgNdx, $srcDocRecData)
  {
    $this->srcDocRecData = $srcDocRecData;

    $this->srcDocNdx = $srcDocRecData['ndx'];

    $this->docGenCfgNdx = $docGenCfgNdx;
    $this->docGenCfg = $this->app()->cfgItem('e10doc.gen.cfgs.'.$docGenCfgNdx, NULL);
  }

	function saveDoc ()
	{
		$this->newDocNdx = $this->tableDocsHeads->dbInsertRec ($this->docHead);
		$this->docHead['ndx'] = $this->newDocNdx;

		$f = $this->tableDocsHeads->getTableForm ('edit', $this->newDocNdx);


		forEach ($this->docRows as $r)
		{
			$r['document'] = $this->newDocNdx;
			$this->tableDocsRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$this->tableDocsHeads->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$this->tableDocsHeads->checkDocumentState ($f->recData);
		$this->tableDocsHeads->dbUpdateRec ($f->recData);
		$this->tableDocsHeads->checkAfterSave2 ($f->recData);

		$this->tableDocsHeads->docsLog ($this->newDocNdx);
	}

  public function generate()
  {
    $this->createDocumentHead();
    $this->createDocumentRows();
    $this->saveDoc();
  }
}

