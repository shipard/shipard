<?php

namespace lib\hosting;

use \e10\TableView, \e10\utils, \e10\TableViewPanel;


/**
 * Class DataSourcesDashboardViewer
 * @package lib\hosting
 */
class DataSourcesDashboardViewer extends \e10pro\hosting\server\ViewDatasources
{
	protected $onlineLimit;
	var $thisUserId = 0;

	public function init ()
	{
		$this->partner = $this->queryParam('partner');
		$this->addAddParam('partner', $this->partner);

		parent::init();

		$this->enableDetailSearch = TRUE;
		$this->usePanelRight = 1;
		$this->fullWidthToolbar = TRUE;

		$this->onlineLimit = new \DateTime();
		$this->onlineLimit->sub (new \DateInterval('PT30M'));

		$this->setPanels (TableView::sptReview);

		if (!$this->thisUserId)
			$this->thisUserId = intval($this->table->app()->user()->data ('id'));
	}

	function panelActiveMainId ($panelId)
	{
		$id = '';

		if ($panelId === 'right')
		{
			$id = 'right-'.$this->partner;
		}

		return $id;
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');

		$qry = [];
		$params = new \E10\Params ($panel->table->app());

		// -- add buttons
		$addButtons = [];
		$addButton = [
			'text' => 'Nová databáze', 'action' => 'wizard', 'icon' => 'system/iconDatabase', 'data-class' => 'e10pro.hosting.client.libs.WizardNewDatasource',
			'data-addParams' => 'partnerNdx='.$this->partner,
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
		];
		$addButtons[] = $addButton;

		$qry[] = ['style' => 'content', 'type' => 'line', 'line' => $addButtons, 'pane' => 'e10-pane-params'];


		// -- dsTypes
		$dsTypes = $this->table->columnInfoEnum('dsType');
		$this->qryPanelAddCheckBoxes($panel, $qry, $dsTypes, 'dsTypes', 'Typ');

		// -- condition
		$conditions = $this->table->columnInfoEnum('condition');
		$this->qryPanelAddCheckBoxes($panel, $qry, $conditions, 'conditions', 'Stav');

		// -- partners
		if ($this->app->hasRole('hstng') && !$this->partner)
		{
			$partners = $this->db()->query('SELECT ndx, name FROM e10pro_hosting_server_partners WHERE docStateMain <= 2 ORDER BY name')->fetchPairs('ndx', 'name');
			$this->qryPanelAddCheckBoxes($panel, $qry, $partners, 'partners', 'Partneři');
		}

		// -- servers
		if ($this->app->hasRole('hstng'))
		{
			$servers = $this->db()->query('SELECT ndx, name FROM e10pro_hosting_server_servers WHERE docStateMain <= 2 AND serverType != 1 AND creatingDataSources != 0 ORDER BY name')->fetchPairs('ndx', 'name');
			$this->qryPanelAddCheckBoxes($panel, $qry, $servers, 'servers', 'Servery');
		}

		// -- modules
		if ($this->app->hasRole('hstng'))
		{
			$modules = $this->db()->query('SELECT ndx, name FROM e10pro_hosting_server_modules WHERE docStateMain != 4')->fetchPairs('ndx', 'name');
			$modules['0'] = 'Žádný';
			$this->qryPanelAddCheckBoxes($panel, $qry, $modules, 'installModules', 'Instalační moduly');
		}

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = array ('style' => 'params', 'title' => $cg['name'], 'params' => $params);
		}
		$params->detectValues();

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar()
	{
		return [];
	}
}
