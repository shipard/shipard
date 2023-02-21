<?php

namespace e10doc\bank\libs;
use \Shipard\Utils\Utils;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewBankDocs
 */
class ViewBankDocs extends \e10doc\core\ViewHeads
{
	var $bankAccountsParam = NULL;
	var $bankAccounts = NULL;
	var $bankAccountsGroups = NULL;
	var $bankAccountNdx = 0;


	public function init ()
	{
		$this->bankAccounts = $this->table->app()->cfgItem ('e10doc.bankAccounts', []);
		$this->bankAccountsGroups = $this->table->app()->cfgItem ('e10doc.bankAccountsGroups', []);

		if (count($this->bankAccounts) > 6 || count($this->bankAccountsGroups))
		{
			$this->usePanelLeft = TRUE;
		}

		$this->docType = 'bank';
		parent::init();

		if ($this->usePanelLeft)
		{
			$enum = [];

			forEach ($this->bankAccounts as $bankAccountNdx => $r)
			{
				if ($r['group'] ?? 0)
					continue;

				$addParams = ['person' => $r['bank'], 'myBankAccount' => $bankAccountNdx, 'currency' => $r['curr']];

				$enum[$bankAccountNdx] = ['text' => $r['shortName'], 'addParams' => $addParams, 'class' => ''];

				if (!$this->bankAccountNdx)
					$this->bankAccountNdx = intval($bankAccountNdx);
			}

			foreach ($this->bankAccountsGroups as $bagNdx => $bagCfg)
			{
				if (!isset($bagCfg['accounts']) || !count($bagCfg['accounts']))
					continue;
				$enum['G'.$bagNdx] = [
					['text' => $bagCfg['sn'], 'class' => '', 'icon' => $bagCfg['icon'], 'unselectable' => 1, 'subItems' => []],
				];
			}

			forEach ($this->bankAccounts as $bankAccountNdx => $r)
			{
				$addParams = ['person' => $r['bank'], 'myBankAccount' => $bankAccountNdx, 'currency' => $r['curr']];

				if ($r['group'] ?? 0)
				{
					$enum['G'.$r['group']][0]['subItems'][$bankAccountNdx] = [
						['text' => $r['shortName'], 'addParams' => $addParams, 'class' => '']
					];
				}
				else
					continue;

				if (!$this->bankAccountNdx)
					$this->bankAccountNdx = intval($bankAccountNdx);
			}

			if (isset($_POST['bankAccount']))
				$this->bankAccountNdx = intval($_POST['bankAccount']);

			$this->bankAccountsParam = new \Shipard\UI\Core\Params ($this->app);
			$this->bankAccountsParam->addParam('switch', 'bankAccount', ['title' => '', 'defaultValue' => strval($this->bankAccountNdx), 'switch' => $enum, 'list' => 1]);
			$this->bankAccountsParam->detectValues();
		}
		else
		{
			$activeBankAccount = key($this->bankAccounts);
			forEach ($this->bankAccounts as $bankAccountNdx => $r)
			{
				$bt [] = [
					'id' => $bankAccountNdx, 'title' => $r['shortName'], 'active' => ($bankAccountNdx == $activeBankAccount),
					'addParams' => ['person' => $r['bank'], 'myBankAccount' => $bankAccountNdx, 'currency' => $r['curr']]
				];
			}
			$this->setBottomTabs ($bt);
		}
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->bankAccountsParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->bankAccountsParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$myBankAccount = 0;

		if ($this->bankAccountNdx)
			$myBankAccount = $this->bankAccountNdx;
		elseif ($this->bankAccountsParam)
			$myBankAccount = intval($this->bankAccountsParam->detectValues()['bankAccount']['value']);
		else
			$myBankAccount = intval($this->bottomTabId ());

		$q [] = 'SELECT';
		array_push ($q, ' heads.ndx, [docNumber], [title], [initBalance], [balance], [debit], [credit], [docOrderNumber],');
		array_push ($q, ' [dateIssue], [dateAccounting], docType, heads.docState, heads.docStateMain, heads.docStateAcc as docStateAcc');
		array_push ($q, ' FROM [e10doc_core_heads] AS heads');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// -- myBankAccount
		if ($myBankAccount)
      array_push ($q, ' AND heads.[myBankAccount] = %i', $myBankAccount);

    // -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, ' AND heads.[docStateMain] < 4');

		// koš
		if ($mainQuery == 'trash')
      array_push ($q, ' AND heads.[docStateMain] = 4');

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [datePeriodBegin] DESC' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [datePeriodBegin] DESC' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$moneySep = ' = ';
		if ($item['initBalance'] < $item['balance'])
			$moneySep = ' ↑ ';
		else
		if ($item['initBalance'] > $item['balance'])
			$moneySep = ' ↓ ';

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon;
		$listItem ['t1'] = strval($item['docOrderNumber']);
		$listItem ['i1'] = Utils::nf ($item['initBalance'], 2) . $moneySep . Utils::nf ($item['balance'], 2);

		$dc = [];
		if ($item['debit'] != 0.0)
			$dc [] = ['icon' => 'system/iconMinusSquare', 'text' => Utils::nf ($item['debit'], 2)];
		if ($item['credit'] != 0.0)
			$dc [] = ['icon' => 'system/iconPlusSquare', 'text' => Utils::nf ($item['credit'], 2)];
		$listItem ['i2'] = $dc;

		$listItem ['t3'] = $item ['title'];

		$props [] = ['icon' => 'system/iconCalendar', 'text' => Utils::datef ($item['dateAccounting'], '%D'), 'class' => ''];

		$docNumber = ['icon' => 'system/iconFile', 'text' => $item ['docNumber'], 'class' => ''];
		if (isset($item['docStateAcc']) && $item['docStateAcc'] == 9)
			$docNumber['class'] = 'e10-error';
		$props [] = $docNumber;

		$listItem ['t2'] = $props;
		return $listItem;
	}
}
