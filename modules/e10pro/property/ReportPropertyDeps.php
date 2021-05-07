<?php

namespace e10pro\property;

use \e10\utils, e10\FormReport;


/**
 * Class ReportPropertyDeps
 * @package e10pro\property
 */
class ReportPropertyDeps extends FormReport
{
	function init ()
	{
		$this->reportId = 'e10pro.property.deps';
		$this->reportTemplate = 'e10pro.property.deps';
		$this->paperOrientation = 'landscape';
	}

	public function loadData ()
	{
		$this->setInfo('icon', 'icon-university');
		$this->setInfo('title', $this->recData['propertyId']);
		$this->setInfo('param', 'Název', $this->recData ['fullName']);

		$de = new \e10pro\property\DepreciationsEngine ($this->app);
		$de->init();
		$de->depOverviewCntCols = 10;
		$de->setProperty($this->recData['ndx']);
		$de->createDepsPlan();
		$de->createInfo();

		$tt = $de->depsOverviewContent();
		$tt[0]['title'] = 'Přehled odpisů';

		$this->data['properties']['depsInfo'] = ['type' => 'table', 'table' => $de->info['depsInfoTable'], 'header' => $de->info['depsInfoHeader']];
		$this->data['properties']['deps'] = $tt[0];

		$this->data['properties']['taxDeps'] = $de->taxDepsContent();
		$this->data['properties']['accDeps'] = $de->accDepsContent();

		$this->data['properties']['depsOverview']  = $de->depsOverviewContentVertical();


		$card = new \e10pro\property\DocumentCardProperty($this->app);
		$card->disableAttachments = TRUE;
		$card->showDeprecations = TRUE;
		$card->setDocument($this->table, $this->recData);
		$card->createContent();

		$this->data['properties']['propsCore'] = $card->content['body'][0];

		$propsExtended = \E10\Base\getPropertiesDetail ($this->table, $this->recData);
		$this->data['properties']['propsExtended'] = [$propsExtended];
	}
}
