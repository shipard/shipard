<?php


namespace wkf\core\libs;
require_once (__APP_DIR__ . '/e10-modules/e10/base/base.php');

use e10\Wizard, e10\str;


/**
 * Class WizardAddFromAttachments
 * @package wkf\core\libs
 */
class WizardAddFromAttachments extends Wizard
{
	var $dstSectionNdx = 0;


	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->saveItems();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->dstSectionNdx = intval($this->app()->testGetParam('dstSectionNdx'));
		$this->recData['dstSectionNdx'] = $this->dstSectionNdx;


		$issuesKinds = \e10\sortByOneKey($this->app()->cfgItem ('wkf.issues.kinds'), 'addOrder', TRUE);
		$issuesTypes = $this->app()->cfgItem ('wkf.issues.types');

		/** @var $tableIssues \wkf\core\TableIssues */
		$tableIssues = $this->app->table ('wkf.core.issues');
		$eik = $tableIssues->enabledIssuesKindForSection ($this->dstSectionNdx);
		$eikEnum = [];
		foreach ($eik as $eikNdx => $oneCfg)
		{
			$eikEnum[$eikNdx] = $oneCfg['fn'];
		}

		if (count($eikEnum))
			$this->recData['issueKind'] = key($eikEnum);

		//$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('dstSectionNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
			$this->addInputEnum2 ('issueKind', 'Druh:', $eikEnum, self::INPUT_STYLE_OPTION);
			$this->addInputFiles();
		$this->closeForm ();
	}

	public function saveItems ()
	{
		/** @var $tableIssues \wkf\core\TableIssues */
		$tableIssues = $this->app->table ('wkf.core.issues');

		$issueKind = $this->app()->cfgItem ('wkf.issues.kinds.'.$this->recData['issueKind'], NULL);
		if (!$issueKind)
			return;

		forEach ($this->recData ['uploadedFiles'] as $oneFile)
		{
			$fn = __APP_DIR__ .'/'.$oneFile;

			$subject = NULL;

			$infoFileName = __APP_DIR__.'/tmp/_upload_' . md5($oneFile) . '.json';
			if (is_readable($infoFileName))
			{
				$infoData = NULL;
				$infoStr = file_get_contents($infoFileName);
				if ($infoStr)
					$infoData = json_decode($infoStr, TRUE);
				if ($infoData && isset($infoData['fileName']))
				{
					$subject = $infoData['fileName'];
				}
			}

			if ($subject === NULL)
			{
				$subjectParts = explode('-', substr($oneFile, 4));
				array_pop($subjectParts);
				$subject = implode('-', $subjectParts);
			}

			$issueRecData = [
				'section' => $this->recData['dstSectionNdx'],
				'issueKind' => $this->recData['issueKind'],
				'issueType' => $issueKind['issueType'],
				'subject' => str::upToLen($subject, 99),
				'source' => 0,
				'docState' => 1001, 'docStateMain' => 0
			];

			$tableIssues->checkNewRec ($issueRecData);
			$newIssueNdx = $tableIssues->dbInsertRec ($issueRecData);
			$issueRecData = $tableIssues->loadItem($newIssueNdx);
			$tableIssues->checkAfterSave2($issueRecData);

			\E10\Base\addAttachments ($this->app, 'wkf.core.issues', $newIssueNdx, $fn, '', FALSE);
			$tableIssues->docsLog ($newIssueNdx);
			unlink ($fn);
		}

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'system/iconInbox'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat zprávy z příloh'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Každý soubor přílohy bude přidán jako jedna nová zpráva'];

		return $hdr;
	}
}
