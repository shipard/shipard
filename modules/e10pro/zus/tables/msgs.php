<?php

namespace e10pro\zus;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \e10\base\libs\UtilsBase;
use \lib\persons\PersonsVirtualGroup;


/**
 * class TableMsgs
 */
class TableMsgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.msgs', 'e10pro_zus_msgs', 'Zprávy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData ['author']) || $recData ['author'] == 0)
			$recData ['author'] = $this->app()->userNdx();
		if (!isset($recData ['msgDate']) || Utils::dateIsBlank($recData ['msgDate']))
			$recData ['msgDate'] = Utils::today();
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (!isset($recData ['msgDate']) || Utils::dateIsBlank($recData ['msgDate']))
			$recData ['msgDate'] = Utils::today();
	}

  public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if ($recData['docState'] === 4000)
		{
			$this->createRecipients($recData);
		}
	}

	function createRecipients ($recData)
	{
    $vyukaRecData = $this->app()->loadItem($recData['vyuka'], 'e10pro.zus.vyuky');

		// -- delete old
		$this->db()->query ('DELETE FROM [e10pro_zus_msgsRecipients] WHERE [sent] = 0 AND [msg] = %i', $recData['ndx']);

		// -- students
    $students = [];
    if ($vyukaRecData['typ'] == 0)
    {  // kolektivni
      $q = [];
      array_push($q, 'SELECT vyukyStudenti.*, studia.student AS studiumStudent FROM [e10pro_zus_vyukystudenti] AS vyukyStudenti');
      array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON vyukyStudenti.studium = studia.ndx');
      array_push($q, ' WHERE [vyuka] = %i', $recData['vyuka']);
      array_push($q, ' ORDER BY [ndx]');

      $rows = $this->db()->query ($q);
      foreach ($rows as $r)
      {
        $studentNdx = $r['studiumStudent'];
        if (!in_array($studentNdx, $students))
          $students[] = $studentNdx;
      }
    }
    else
    {
      $students[] = $vyukaRecData['student'];
    }

    if (!count($students))
      return;

    // -- contacts
    $q = [];
    array_push($q, 'SELECT * FROM [e10_persons_personsContacts]');
    array_push($q, ' WHERE [person] IN %in', $students);
    array_push($q, ' AND [flagContact] = 1');
    array_push($q, ' ORDER BY [contactName], [ndx]');

    $emails = [];
    $rows = $this->db()->query ($q);
    foreach ($rows as $r)
    {
      $email = $r['contactEmail'];
      if (trim($email) === '')
        continue;
      if (in_array($email, $emails))
        continue;
      $emails[] = $email;

      $rcp = [
        'msg' => $recData['ndx'],
        'person' => $r['person'],
        'personContact' => $r['ndx'],
        //'email' => $email,
        'sent' => 0,
      ];
      error_log("INSERT: ".json_encode($rcp));
      $this->db()->query ('INSERT INTO [e10pro_zus_msgsRecipients] ', $rcp);
    }
	}
}


/**
 * class ViewMsgs
 */
class ViewMsgs extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;

	var $linkedPersons = [];
	var $classification = [];

	public function init ()
	{
		$this->linesWidth = 45;
		$this->objectSubType = TableView::vsMain;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		parent::init();

		//$this->textRenderer = new \lib\core\texts\Renderer($this->app());
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['title'];
    $listItem ['t2'] = $item['nazevVyuky'];
    $listItem ['i2'] = ['text' => Utils::datef($item['msgDate']), 'suffix' => $item['authorName']];

		$c = '';
		$c .= "<div class='pageText padd5' style='border: 1px solid gray; margin: .5ex;'>";
		$c .= '<h3>'.Utils::es($item['title']).'</h3>';

		//$this->textRenderer->render ($item ['text']);
		//$c .= $this->textRenderer->code;

		$c .= '</div>';

		//$listItem ['code'] = $c;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT msgs.*, vyuky.nazev AS nazevVyuky, authors.fullName AS authorName';
		array_push($q, ' FROM [e10pro_zus_msgs] AS [msgs]');
    array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON msgs.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS authors ON msgs.author = authors.ndx');

		array_push($q, ' WHERE 1');

		if (!$this->app->hasRole('zusadm'))
		{
			array_push($q, ' AND msgs.[author] = %i', $this->app->userNdx());
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q,
				' msgs.[title] LIKE %s', '%'.$fts.'%',
				' OR msgs.[text] LIKE %s', '%'.$fts.'%',
        ' OR vyuky.[nazev] LIKE %s', '%'.$fts.'%'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[msgs].', ['[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormMsg
 */
class FormMsg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Zpráva', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('title');
			$this->openTabs ($tabs);
				$this->openTab (self::ltNone);
					$this->addInputMemo('text', NULL, TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab();
					$this->addColumnInput ('vyuka');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('author');
					$this->addColumnInput ('msgDate');
				$this->closeTab();
				$this->openTab(TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailMsg
 */
class ViewDetailMsg extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.libs.dc.MsgCore');
	}
}


/**
 * class ViewDetailMsgRecipients
 */
class ViewDetailMsgRecipients extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent (
			[
				'type' => 'viewer', 'table' => 'e10pro.zus.msgsRecipients', 'viewer' => 'e10pro.zus.ViewMsgsRecipients',
				'params' => ['msgNdx' => $this->item ['ndx']]
			]);
	}
}
