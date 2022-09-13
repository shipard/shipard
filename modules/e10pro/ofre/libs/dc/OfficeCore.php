<?php

namespace e10pro\ofre\libs\dc;
use \Shipard\Base\DocumentCard;


/**
 * class OfficeCore
 */
class OfficeCore extends DocumentCard
{
  /** @var \e10\persons\TablePersons $tablePersons */
  var $tablePersons;

  var $personRecData = NULL;

  var \e10pro\ofre\libs\OfficeInfo $officeInfo;
  var \e10\persons\DocumentCardPerson $dcPerson;


  public function createContentBody ()
	{
    if (count($this->officeInfo->data['vdsContent']))
    {
      foreach ($this->officeInfo->data['vdsContent'] as $cc)
      {
        $cc['pane'] = 'e10-pane e10-pane-table';
        $cc['params'] = ['hideHeader' => 1, ];
        $this->addContent('body', $cc);
      }
    }

    if (isset($this->officeInfo->data['personsList']))
      $this->addContent('body', $this->officeInfo->data['personsList']);

    if (isset($this->officeInfo->data['rowsContent']))
      $this->addContent('body', $this->officeInfo->data['rowsContent']);

    if (isset($this->officeInfo->data['issues']))
    {
      $this->addContent('body', $this->officeInfo->data['issues']);
    }

    if (isset($this->officeInfo->data['rowsMetersReadings']))
      $this->addContent('body', $this->officeInfo->data['rowsMetersReadings']);
  }

  public function createContent ()
	{
    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->personRecData = $this->tablePersons->loadItem($this->recData['customer']);
    $this->dcPerson = new \e10\persons\DocumentCardPerson($this->app());
		$this->dcPerson->setDocument($this->tablePersons, $this->personRecData);
    $this->dcPerson->loadData();
    $this->dcPerson->privacy = NULL;
		$personContent = $this->dcPerson->contentContacts();
    $paneTitle = [[
      'text' => $this->dcPerson->recData['fullName'], 'class' => 'e10-me',
      'icon' => $this->dcPerson->table->tableIcon($this->dcPerson->recData)
    ]];
    if ($this->dcPerson->ids)
    {
      foreach (array_reverse ($this->dcPerson->ids) as $oneId)
      {
        $oneId['class'] = 'pull-right e10-small e10-tag';
        $paneTitle[] = $oneId;
      }
    }
    $personContent['title'] = $paneTitle;

    $personContent['pane'] = 'e10-pane';
    $this->addContent('body', $personContent);

    $this->officeInfo = new \e10pro\ofre\libs\OfficeInfo($this->app());
    $this->officeInfo->setWorkOrder($this->recData['ndx']);
    $this->officeInfo->loadInfo();

		$this->createContentBody ();
	}
}