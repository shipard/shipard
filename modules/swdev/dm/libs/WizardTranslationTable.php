<?php

namespace swdev\dm\libs;

use \e10\utils, \E10\TableForm;


/**
 * Class WizardTranslationTable
 * @package swdev\dm\libs
 */
class WizardTranslationTable extends \E10\Wizard
{
	var $tableNdx = 0;
	var $tableRecData;
	var $langNdx = 0;

	/** @var \swdev\dm\TableTables */
	var $tableTables;
	/** @var \swdev\dm\TableDMTrTexts */
	var $tableDMTrTexts;
	/** @var \swdev\dm\libs\TranslationTable */
	var $tt;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doUpdate();
			$this->stepResult['lastStep'] = 1;
			$this->stepResult ['close'] = 1;
			$this->stepResult ['refreshDetail'] = 1;
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
		$this->detectSrcDoc();

		$this->setFlag ('formStyle', 'e10-formStyleWizard');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addInput('srcDocNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->addInput('langNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->layoutOpen(self::ltRenderedTable);
				$this->addInputs();
			$this->layoutClose();
		$this->closeForm ();
	}


	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-table'];

		$hdr ['info'][] = ['class' => 'title', 'value' => $this->tableRecData['name']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->tableRecData['id']];

		return $hdr;
	}

	function detectSrcDoc()
	{
		$this->tableNdx = intval($this->app->testGetParam('focusedPK'));
		$this->recData['tableNdx'] = strval($this->tableNdx);

		$this->langNdx = intval($this->app()->testGetParam('lang'));
		$this->recData['langNdx'] = strval($this->langNdx);

		$this->tableTables = $this->app()->table('swdev.dm.tables');
		$this->tableDMTrTexts = $this->app()->table('swdev.dm.dmTrTexts');
		$this->tableRecData = $this->tableTables->loadItem ($this->tableNdx);

		$this->tt = new \swdev\dm\libs\TranslationTable($this->app());
		$this->tt->init();
		$this->tt->setTableNdx($this->tableNdx);
		$this->tt->loadColumns();
		$this->tt->loadTrTexts();
	}

	function addInputs()
	{
		$ic = '';

		$ic .= "<table class='default fullWidth'>";
		foreach ($this->tt->tableColumns as $tcNdx => $tc)
		{
			// -- name
			$trColName = NULL;
			$trColNameDSClass = '';
			if (isset($this->tt->trTexts['cols'][$tcNdx][$this->langNdx]['1']))
			{
				$trColName = $this->tt->trTexts['cols'][$tcNdx][$this->langNdx]['1'];
				$trColNameDSClass = $this->tt->dsClasses[$trColName['ds']];
			}
			$ic .= "<tr>";
			$ic .= "<td class='number e10-ds-block $trColNameDSClass'>".utils::es($tc['name']).'</td>';
			$ic .= "<td>";
			if ($trColName)
			{
				$colId = 'col-'.$trColName['pk'].'-name';
				$this->addInput($colId, NULL, self::INPUT_STYLE_STRING, 0, 150);
				$ic .= $this->lastInputCode;
				$this->recData[$colId] = $trColName['t'];
			}
			$ic .= '</td>';

			// -- label
			$trColLabel = NULL;
			$trColLabelDSClass = '';
			if (isset($this->tt->trTexts['cols'][$tcNdx][$this->langNdx]['2']))
			{
				$trColLabel = $this->tt->trTexts['cols'][$tcNdx][$this->langNdx]['2'];
				$trColLabelDSClass = $this->tt->dsClasses[$trColName['ds']];
			}

			$ic .= "<td class='number e10-ds-block $trColLabelDSClass'>".utils::es($tc['label']).'</td>';
			$ic .= "<td>";
			if ($trColLabel)
			{
				$colId = 'col-'.$trColLabel['pk'].'-label';
				$this->addInput($colId, NULL, self::INPUT_STYLE_STRING, 0, 150);
				$ic .= $this->lastInputCode;
				$this->recData[$colId] = $trColLabel['t'];
			}
			$ic .= '</td>';

			$ic .= '</tr>';
		}
		$ic .= '</table>';

		$this->appendCode($ic);
	}

	function doUpdate()
	{
		$this->tableDMTrTexts = $this->app()->table('swdev.dm.dmTrTexts');

		foreach ($this->recData as $key => $value)
		{
			if (substr($key, 0, 4) !== 'col-')
				continue;

			$parts = explode('-', $key);
			if (count($parts) !== 3)
				continue;
			$textNdx = intval($parts[1]);
			$existedText = $this->tableDMTrTexts->loadItem($textNdx);
			if (!$existedText)
				continue;
			if ($existedText['text'] === $value)
				continue;

			$this->app()->db()->query('UPDATE [swdev_dm_dmTrTexts] SET [text] = %s', $value, ' WHERE ndx = %i', $textNdx);
			$this->tableDMTrTexts->docsLog($textNdx);
		}

		return TRUE;
	}
}
