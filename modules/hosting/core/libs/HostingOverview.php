<?php

namespace hosting\core\libs;
use \Shipard\Base\Content;


/**
 * @class HostingOverview
 */
class HostingOverview extends Content
{
	var $code = '';
  var $servers = [];

  public function loadData()
  {
    // -- servers
		$q[] = 'SELECT [servers].*, [owners].[fullName] AS [ownerFullName],';
		array_push($q, ' CONCAT(COALESCE([hwServers].name, [servers].name), [servers].name) AS serverOrder');
		array_push($q, ' FROM [hosting_core_servers] AS [servers]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [owners] ON [servers].[owner] = [owners].[ndx]');
		array_push($q, ' LEFT JOIN [hosting_core_servers] AS [hwServers] ON [servers].[hwServer] = [hwServers].[ndx]');
		array_push($q, ' WHERE 1');
    array_push($q, ' AND servers.[docState] IN %in', [4000, 8000]);
    array_push($q, ' ORDER BY serverOrder');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($r['serverRole'] === 1)
      {
        $this->servers[] = ['name' => $r['name'], 'ndx' => $r['ndx']];
      }
    }
  }

	public function createCode()
	{
		//$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-align-stretch']);

    $this->addContent (['type' => 'grid', 'cmd' => /*'e10-fx-row e10-fx-align-stretch e10-fx-wrap e10-widget-top-bar-ext'*/ 'e10-fx-row e10-fx-grow e10-fx-wrap e10-widget-top-bar-ext']);
    foreach ($this->servers as $srv)
    {
		  $this->addContent ([
        'type' => 'widget', 'id' => 'hosting.core.libs.WidgetHostingServer', 
        'class' => 'e10-fx-col e10-fx-4 e10-fx-sm-fw pa1', 'params' => ['serverNdx' => $srv['ndx']]
      ]);
    }  
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
    
//		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$cr = new \e10\ContentRenderer($this->app);
		$cr->content = $this->content;
		$this->code .= $cr->createCode('body');
	}

	public function run ()
	{
    $this->loadData();
		$this->createCode();
	}
}
