<?php


namespace e10doc\templates\libs\dc;
use \Shipard\Base\DocumentCard;
use \e10doc\templates\TableHeads;


/**
 * class TemplateCore
 */
class TemplateCore extends DocumentCard
{
  protected function createContentWorkOrders()
  {
    $q = [];
    array_push ($q, 'SELECT [wo].*');
    array_push ($q, ' FROM e10mnf_core_workOrders AS [wo]');
    array_push ($q, ' WHERE 1');

    array_push ($q, ' AND [wo].docKind = %i', $this->recData['srcWorkOrderKind']);
    array_push ($q, ' LIMIT 50');

    $t = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
       $item = [
        'docNumber' => $r['docNumber'],
        'title' => $r['title'],
       ];

       $t[] = $item;
    }

    $h = ['#' => '#', 'docNumber' => ' Zak.', 'title' => 'Název'];
    $title = ['text' => 'pokus'];

    $this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
			'type' => 'table', 'table' => $t, 'header' => $h
		]);
  }

  public function createContentBody ()
	{
    if ($this->recData['templateType'] === TableHeads::ttWOGen)
    {
      $this->createContentWorkOrders();
    }
  }

  public function createContent ()
	{
		$this->createContentBody ();
	}
}