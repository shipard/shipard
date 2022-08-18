<?php


namespace wkf\bboard\libs\dc;
use \Shipard\Base\DocumentCard, \Shipard\Utils\Utils;


/**
 * class MsgCore
 */
class MsgCore extends DocumentCard
{
  protected function createContentAgenda()
  {
		$q [] = 'SELECT agenda.*';
		array_push ($q, ' FROM [wkf_bboard_msgsAgenda] AS [agenda]');
		array_push ($q, ' WHERE 1');

    array_push ($q, ' AND [agenda].[msg] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER BY [agenda].[dateBegin], [agenda].ndx');

    $rows = $this->db()->query($q);
    $t = [];
    foreach ($rows as $r)
    {
      $item = [
        'title' => $r['title'],
        'begin' => Utils::datef($r['dateBegin'], '%D, %T'),
        'end' => Utils::datef($r['dateEnd'], '%D, %T'),

        'action' => ['text' => '', 'icon' => 'system/actionOpen', 'pk' => $r['ndx'], 'table' => 'wkf.bboard.msgsAgenda', 'docAction' => 'edit', 'type' => 'span'],
      ];

      $t[] = $item;
    }

    $h = ['#' => '#', 'action' => '|', 'begin' => 'Začátek', 'end' => 'Konec', 'title' => 'Název', ];
    $paneTitle = [
      ['text' => 'Program', 'class' => 'h1 pb1']
    ];
		$paneTitle[] = [
      'text'=> 'Přidat', 'docAction' => 'new', 'table' => 'wkf.bboard.msgsAgenda', 'type' => 'button',
      'actionClass' => 'btn btn-success btn-xs mb1', 'icon' => 'system/actionAdd', 'class' => 'pull-right',
      'addParams' => "__msg={$this->recData['ndx']}"
    ];

    $this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $t, 'header' => $h,
      'paneTitle' => $paneTitle,
			'__params' => ['forceTableClass' => 'dcInfo fullWidth', 'hideHeader' => 1]
		]);

  }

  public function createContentBody ()
	{
		//$bboardCfg = $this->app()->cfgItem('wkf.bboard.bboards.'.$this->recData['bboard'], NULL);

    // -- text
    if ($this->recData ['text'] && $this->recData ['text'] !== '')
    {
      $textRenderer = new \lib\core\texts\Renderer($this->app());
      $textRenderer->render($this->recData ['text']);
      $this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'e10-pane e10-pane-table pageText']);
    }

    if ($this->recData['isEvent'])
      $this->createContentAgenda();
  }

  public function createContent ()
	{
		$this->createContentBody ();
	}
}