<?php

namespace e10\users\libs;
use \Shipard\Form\Wizard;


/**
 * class ResetPasswordWizard
 */
class ResetPasswordWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->resetPassword();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
    $userNdx = intval ($this->app()->testGetParam('focusedPK'));
    $this->recData['userNdx'] = $userNdx;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
      $this->addInput('userNdx', 'Uživatel', self::INPUT_STYLE_STRING, self::coHidden, 60);
			$this->addInput('newPassword', 'Nové heslo', self::INPUT_STYLE_STRING, 0, 60);
		$this->closeForm ();
	}

	public function resetPassword ()
	{
    $userNdx = intval($this->recData['userNdx']);
    $existedPassword = $this->app()->db()->query('SELECT * FROM [e10_users_pwds] WHERE [user] = %i', $userNdx)->fetch();
    if (!$existedPassword)
    {
      return;
    }

    $newPassword = password_hash($this->recData['newPassword'], PASSWORD_BCRYPT, ['cost' => 12]);
    $this->app()->db()->query('UPDATE [e10_users_pwds] SET [password] = %s', $newPassword, ' WHERE ndx = %i', $existedPassword['ndx']);

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
    $userNdx = intval($this->recData['userNdx'] ?? $this->app()->testGetParam('focusedPK'));
    $existedUser = $this->app()->db()->query('SELECT * FROM [e10_users_users] WHERE [ndx] = %i', $userNdx)->fetch();

		$hdr = [];
		$hdr ['icon'] = 'user/key';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Reset hesla'];
		if ($existedUser)
			$hdr ['info'][] = ['class' => 'info', 'value' => $existedUser['fullName']];

		return $hdr;
	}
}
