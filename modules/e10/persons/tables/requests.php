<?php

namespace E10\Persons;

require_once __DIR__ . '/../../base/base.php';


use \E10\Application, \E10\utils;
use \E10\TableView;
use \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;


/**
 * Tabulka Požadavky
 *
 */

class TableRequests extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.persons.requests", "e10_persons_requests", "Požadavky na správu osob");
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['requestId']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['subject']];

		return $hdr;
	}
}


/** 
 * Základní pohled na Požadavky
 * 
 */

class ViewRequests extends TableView
{
	public function init ()
	{
		parent::init();

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'done', 'title' => 'Vyřízeno');
		$mq [] = array ('id' => 'archive', 'title' => 'Expirováno');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = "x-request";
		$listItem ['t1'] = $item['subject'];

		$props [] = array ('icon' => 'system/actionPlay', 'text' => \E10\df ($item['created']));
		$props [] = array ('icon' => 'system/iconSitemap', 'text' => $item['addressCreate']);
		$listItem ['t2'] = $props;

		if ($item['finished'])
		{
			$props2 [] = array ('icon' => 'system/iconCheck', 'text' => \E10\df ($item['finished']));
			$props2 [] = array ('icon' => 'system/iconSitemap', 'text' => $item['addressConfirm']);
			$listItem ['t3'] = $props2;
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10_persons_requests] as heads WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
 			array_push ($q, " AND heads.[subject] LIKE %s", '%'.$fts.'%');
		}

    // -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND heads.[docStateMain] = 0");

		// done
		if ($mainQuery == 'done')
      array_push ($q, " AND heads.[docStateMain] = 2");

		// archive
		if ($mainQuery == 'archive')
      array_push ($q, " AND heads.[docStateMain] = 5");

		// koš
		if ($mainQuery == 'trash')
      array_push ($q, " AND heads.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [ndx]' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [ndx]' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows


} // class ViewRequests


/**
 * Základní detail Požadavku
 *
 */

class ViewDetailRequest extends TableViewDetail
{
	public function createDetailContent()
	{
		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => utils::json_lint ($this->item['requestData'])));
	}
}


/* 
 * FormRequest
 * 
 */

class FormRequest extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ("subject");
			$this->addColumnInput ("requestData");
		$this->closeForm ();
	}
} // class FormGroups

