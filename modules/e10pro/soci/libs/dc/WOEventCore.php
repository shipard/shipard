<?php

namespace e10pro\soci\libs\dc;
use \Shipard\Base\DocumentCard;


/**
 * class WOEventCore
 */
class WOEventCore extends DocumentCard
{
  var \e10pro\soci\libs\WOEventInfo $woInfo;

  public function createContentBody ()
	{
    if (isset($this->woInfo->data['vdsContent']) && count($this->woInfo->data['vdsContent']))
    {
      foreach ($this->woInfo->data['vdsContent'] as $cc)
      {
        if (!count($cc['table'] ?? []))
          continue;
        $cc['pane'] = 'e10-pane e10-pane-table';
        $cc['params'] = ['hideHeader' => 1, ];
        $this->addContent('body', $cc);
      }
    }

    if ($this->woInfo->woKind['usePersonsList'])
    {

      if ($this->woInfo->data['personsList'] && count($this->woInfo->data['personsList']['table']))
      {
        $title = [['text' => 'Evidenční list', 'class' => 'h2']];

        $this->woInfo->data['personsList']['paneTitle'] = $title;
        $this->addContent('body', $this->woInfo->data['personsList']);
      }
    }
    else
    {
      if ($this->woInfo->data['members'] && count($this->woInfo->data['members']['table']))
      {
        $title = [['text' => 'Evidenční list', 'class' => 'h2']];
        $title [] = [
          'type' => 'action', 'action' => 'addwizard',
          'text' => 'Vystavit faktury', 'data-class' => 'e10pro.soci.libs.WizardEventIvoicing',
          'icon' => 'system/actionAdd', 'class' => 'pull-right padd5', 'actionClass' => 'btn-sm',
        ];

        $this->woInfo->data['members']['paneTitle'] = $title;
        $this->addContent('body', $this->woInfo->data['members']);
      }
    }
  }

  public function createContent ()
	{
    $this->woInfo = new \e10pro\soci\libs\WOEventInfo($this->app());
    $this->woInfo->setWorkOrder($this->recData['ndx']);
    $this->woInfo->loadInfo();

		$this->createContentBody ();
	}
}