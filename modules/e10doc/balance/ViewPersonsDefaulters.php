<?php

namespace e10doc\balance;

use \e10\utils, \e10\Utility, \e10\TableView, \e10doc\core\libs\E10Utils;


/**
 * Class ViewPersonsDefaulters
 * @package e10doc\balance
 *
 * Prohlížeč dlužníků.
 */

class ViewPersonsDefaulters extends TableView
{
	var $fiscalYear;
	public function init ()
	{
		$this->fiscalYear = E10Utils::todayFiscalYear($this->app());

		$this->rowAction = 'widget';
		$this->rowActionClass = 'e10doc.finance.CashPayWidget';

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$ubg = E10Utils::usersBalancesGroups($this->app);

		$q [] = 'SELECT personNdx, fullName, company, gender, lastName, ';
		array_push($q, ' currency, ABS(SUM(receivables)) as receivables, ABS(SUM(obligations)) as obligations');

		array_push($q, ' FROM ');
		array_push($q,
				'(',
				'SELECT p.ndx as personNdx, p.fullName, p.company, p.gender, p.lastName, b.currency,',
				' (b.request-b.payment)*IF(b.type=1000,1,0) as receivables, (b.request-b.payment)*IF(b.type=2000,1,0) as obligations',
				' FROM e10doc_balance_journal as b LEFT JOIN e10_persons_persons as p ON (b.person = p.ndx)',
				' WHERE [type] IN %in', [1000, 2000], ' AND fiscalYear = %i', $this->fiscalYear);

		if ($this->app()->hasRole ('finance') || $this->app()->hasRole ('bsass') || $this->app()->hasRole ('audit'))
		{

		}
		else
		if ($ubg !== FALSE)
		{
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsgroups as pg WHERE b.person = pg.person');
			array_push($q, ' AND pg.[group] IN %in)', $ubg);
		}
		else
			array_push($q, ' AND p.ndx = %i', -1);

		array_push($q, ') AS b1');

		array_push($q, ' GROUP BY personNdx, fullName, currency');
		array_push($q, ' HAVING (SUM(receivables) > 0)');

		if ($fts !== '')
			array_push ($q, ' AND ([fullName] LIKE %s)', '%'.$fts.'%');

		array_push($q, ' ORDER BY lastName, personNdx');
		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['personNdx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i2'] = ['text' => utils::nf ($item['receivables'], 2)];
		$listItem ['i2']['prefix'] = $item['currency'];

		return $listItem;
	}
}
