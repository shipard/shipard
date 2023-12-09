<?php
namespace hosting\core\libs;


/**
 * class PartnersDSReport
 */
class PartnersDSReport extends \e10doc\core\libs\reports\DocReportBase
{
	function init ()
	{
		parent::init();
		$this->setReportId('reports.modern.hosting.core.partnersDS');
	}

	public function loadData ()
	{
		parent::loadData();

		// -- person
		$this->loadData_MainPerson('person', $this->recData['owner']);

		// -- owner
		$this->loadData_DocumentOwner (0);

		$dse = new \hosting\core\libs\PartnersDSEngine($this->app());
		$dse->setPartner($this->recData['ndx']);
		$dse->print = 1;
		$dse->run();

		$content = $dse->createContentDataSources();
		$this->data['contents'] = [$content];

		$priceLegend = $dse->createContentPlansLegend();
		$this->data['priceLegend'] = [$priceLegend];
	}
}

