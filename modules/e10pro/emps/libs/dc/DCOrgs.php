<?php

namespace e10pro\emps\libs\dc;


/**
 * class DCOrgs
 */
class DCOrgs extends \Shipard\Base\DocumentCard
{
	protected function addDetail()
	{
		$orgsInfo = new \e10pro\emps\libs\OrgsInfo($this->app());
		$orgsInfo->setOrgs($this->recData['ndx']);
		$orgsInfo->loadData();

		$orgsContent = $orgsInfo->orgsPersonsContent;
		$orgsContent['pane'] = 'e10-pane e10-pane-table';
		$this->addContent('body', $orgsContent);
	}

	public function createContentBody ()
	{
		$this->addDetail();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
