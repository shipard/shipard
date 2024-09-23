<?php

namespace e10pro\bume\libs;


/**
 * class PersonsSyncPullResponse
 */
class PersonsSyncPullResponse extends \e10sync\libs\SyncPullServerResponse
{
  var $style = 'list';
  var $listNdx = 0;
  var $listPageSize = 0;
  var $listFirstRowNumber = 0;
  var $personNdx = 0;
  var $paramsOk = 0;

  var \e10pro\bume\libs\ContactsListEngine $contactsListEngine;

  protected function checkRequestsParams()
  {
    $this->style = $this->requestParam('style', 'list');

    $this->listNdx = intval($this->requestParam('listNdx', 0));
    if (!$this->listNdx)
    {
      $this->result['err'] = 'Missing / invalid `listNdx` param';
      return;
    }

    $listRecData = $this->app()->loadItem($this->listNdx, 'e10pro.bume.lists');
    if (!$listRecData)
    {
      $this->result['err'] = 'Invalid `listNdx` #'.$this->listNdx.' (list not exist)';
      return;
    }

    $this->paramsOk = 1;
  }

  protected function doList()
  {
    $this->listPageSize = intval($this->requestParam('pageSize', 10));
    $this->listFirstRowNumber = intval($this->requestParam('firstRowNumber', 0));

		$this->contactsListEngine = new \e10pro\bume\libs\ContactsListEngine($this->app());
		$this->contactsListEngine->setList($this->listNdx);
//		$this->contactsListEngine->createRecipients();
		$this->contactsListEngine->createSyncList($this->listPageSize, $this->listFirstRowNumber);

    $this->result['data'] = $this->contactsListEngine->data;
  }

  protected function doPerson()
  {
    $this->personNdx = intval($this->requestParam('personNdx', 10));
    if (!$this->personNdx)
    {
      $this->result['err'] = 'Invalid / missing `personNdx` #'.$this->personNdx;
      return;
    }
    $personExist = $this->db()->query('SELECT * FROM [e10pro_bume_listPersons] WHERE [person] = %i', $this->personNdx,
                                      ' AND [list] = %i', $this->listNdx)->fetch();
    if (!$personExist)
    {
      $this->result['err'] = '`personNdx` #'.$this->personNdx.' not found in `listNdx` #'.$this->listNdx;
      return;
    }

    /** @var \e10\persons\TablePersons */
    $tablePersons = $this->app()->table('e10.persons.persons');
    $personItem = $this->loadItem($this->personNdx, $tablePersons);
    if (!$personItem)
    {
      $this->result['err'] = '`personNdx` #'.$this->personNdx.' not exist';
      return;
    }

    unset($personItem['lists']['address']);
    unset($personItem['lists']['groups']);

    // -- contacts
    $personItem['associated']['contacts'] = ['table' => 'e10.persons.personsContacts', 'recs' => []];


    /** @var \e10\persons\TablePersonsContacts */
    $tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');

    $cl = [];

    $q = [];
    array_push($q, 'SELECT contacts.ndx FROM [e10_persons_personsContacts] AS contacts');
    array_push($q, ' WHERE [contacts].[person] = %i', $this->personNdx);
    array_push($q, ' AND [contacts].[flagContact] = %i', 1);
    array_push($q, ' AND [contacts].[docState] = %i', 4000);
    array_push($q, ' ORDER BY [contacts].[onTop], [contacts].[systemOrder], [contacts].[ndx]');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $cl[] = $r['ndx'];
      $personContact = $this->loadItem($r['ndx'], $tablePersonsContacts);
      unset($personContact['lists']);
      $personItem['associated']['contacts']['recs'][] = $personContact;
    }

    $this->result['person'] = $personItem;
  }

  public function run()
  {
    $this->checkRequestsParams();
    if (!$this->paramsOk)
      return;

    if ($this->style === 'list')
    {
      $this->doList();
    }
    elseif ($this->style === 'person')
    {
      $this->doPerson();
    }
  }
}
