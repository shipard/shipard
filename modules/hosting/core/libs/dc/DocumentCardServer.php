<?php

namespace hosting\core\libs\dc;


/**
 * @class DocumentCardServer
 */
class DocumentCardServer extends \Shipard\Base\DocumentCard
{
  var $serverInfoRecData = NULL;
  var $serverInfoCore = NULL;

	function loadData()
	{
    $this->serverInfoRecData = $this->db()->query('SELECT * FROM [hosting_core_serversInfo] WHERE [server] = %i', $this->recData['ndx'])->fetch();
    if (!$this->serverInfoRecData)
      return;

    $this->serverInfoCore = json_decode($this->serverInfoRecData['dataCore'], TRUE);
	}

	public function createContentBody ()
	{
    if (!$this->serverInfoCore)
    {
      $this->addContent ('body', [
        'pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => ['text' => 'informace zatím nejsou k dispozici']
      ]);
      return;
    }

		$info = [];

		// -- OS
		$info[] = [
			'p1' => 'OS',
			't1' => $this->serverInfoCore['os']['fullName'] ?? '???',
		];

    // -- shipard channels
    $shpVersions = [];
    foreach ($this->serverInfoCore['shipardServerChannels'] as $channelId => $channel)
    {
      $shpVersions [] = ['text' => $channelId, 'class' => 'e10-bold'];
      $shpVersions [] = ['text' => $channel['version'], 'class' => 'label label-default'];
      $shpVersions [] = ['text' => '', 'class' => 'break'];
    }
    $info[] = [
      'p1' => 'Shipard Server',
      't1' => $shpVersions
    ];

		// -- time zone
    $info[] = [
      'p1' => 'Time zone',
      't1' => $this->serverInfoCore['timeZone'] ?? '---',
    ];

    // -- mainSW
    foreach ($this->serverInfoCore['mainSW'] as $swId => $sw)
    {
      $info[] = [
        'p1' => $sw['title'] ?? $swId,
        't1' => $sw['version'],
      ];
    }

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody ();
	}
}
