<?php

namespace demo\documents\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


use \e10\json, \e10\utils;


/**
 * Class Invni
 * @package lib\demo\documents\docs
 */
class Invni extends \demo\documents\libs\Core
{
	public function init ($taskDef, $taskTypeDef)
	{
		parent::init($taskDef, $taskTypeDef);

		$this->defaultValues['docState'] = 1000;
		$this->defaultValues['docStateMain'] = 0;

		$this->data['rec']['docType'] = 'invni';

		$dbCounters = $this->app()->cfgItem ('e10.docs.dbCounters.' . 'invni', ['1' => []]);
		$activeDbCounter = key($dbCounters);
		$this->data['rec']['dbCounter'] = $activeDbCounter;

		$this->data['rec']['currency'] = 'czk';
		$this->data['rec']['paymentMethod'] = 0;
		$this->data['rec']['taxCalc'] = 1;
		$this->data['rec']['roundMethod'] = 1;

		$this->data['rec']['symbol1'] = time();

		$dateDue = utils::today();
		$dateDue->add (new \DateInterval('P14D'));
		$this->data['rec']['dateDue'] = $dateDue->format('Y-m-d');

		$this->addRows();
	}

	public function save()
	{
		parent::save();

		// -- create inbox
		$engine = new \demo\documents\libs\DemoDocInbox($this->app());
		$engine->disableDocLink = TRUE;
		$engine->init(['docNdx' => $this->newNdx]);
		$engine->createInbox();
		$newMsgNdx = $engine->newIssueNdx;
		unset($engine);

		// -- save json to tmp
		$fn = __APP_DIR__.'/tmp/inbox-document-'.$newMsgNdx.'.json';
		file_put_contents($fn, json::lint($this->pkg));

		// -- delete document
		$this->db()->query ('DELETE FROM [e10_base_docslog] WHERE [tableid] = %s', 'e10doc.core.heads',' AND [recid] = %i', $this->newNdx);
		$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $this->newNdx);
		$this->db()->query ('DELETE FROM [e10doc_core_taxes] WHERE [document] = %i', $this->newNdx);
		$this->db()->query ('DELETE FROM [e10doc_core_heads] WHERE [ndx] = %i', $this->newNdx);
	}
}
