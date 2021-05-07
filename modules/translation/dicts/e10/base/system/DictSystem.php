<?php

namespace translation\dicts\e10\base\system;
use \e10\Application, \e10\utils;

class DictSystem
{
	 static$path = __SHPD_MODULES_DIR__.'translation/dicts/e10/base/system';
	 static$baseFileName = 'DictSystem';
	 static$data = NULL;

		const
			 diCore_TechnicalSupport = 0
			,diLoginForm_LoginButton = 1
			,diLoginForm_FormTitle = 2
			,diLoginPage_Menu_LostPassword = 3
			,diLoginPage_Menu_Login = 4
			,diLoginForm_RememberMe = 5
			,diLostPasswordForm_FormTitle = 6
			,diLostPasswordForm_SendButton = 7
			,diLostPasswordForm_InfoText = 8
			,diLostPasswordForm_DoneText = 9
			,diLostPasswordForm_Error_BlankEmail = 10
			,diLostPasswordForm_Error_InvalidEmail = 11
			,diLostPasswordForm_Error_UnknownEmail = 12
			,diCore_Email = 13
			,diCore_Password = 14
			,diCore_Version = 15
			,diBtn_Insert = 16
			,diBtn_Open = 17
			,diBtn_Copy = 18
			,diBtn_SendViaEmail = 19
			,diBtn_Print = 20
			,diBtn_Logout = 21
			,diLoginForm_Error_WrongLoginOrPassword = 22
			,diBtn_Tablet = 23
			,diBtn_Computer = 24
			,diBtn_Close = 25
			,diBtn_Save = 26
			,diBtn_Saved = 27
			,diForm_Content = 28
			,diForm_Settings = 29
			,diForm_Attachments = 30
			,diForm_Header = 31
			,diForm_NoteExternal = 32
			,diForm_Accounting = 33
			,diForm_Sorting = 34
			,diForm_Properties = 35
			,diForm_Document = 36
			,diForm_Accessories = 37
			,diForm_Perex = 38
			,diForm_ColumnLeft = 39
			,diForm_ColumnRight = 40
			,diForm_Text = 41
			,diForm_Code = 42
			,diForm_StylesExtension = 43
			,diForm_Poster = 44
			,diForm_Print = 45
			,diBtn_Delete = 46
			,diBtn_Seen = 47
			,diBtn_AddFiles = 48
			,diForm_ChooseFiles = 49
			,diForm_SaveDocument = 50
			,diBtn_Replacement = 51
			,diBtn_Sent = 52
			,diForm_DocNotConcluded = 53
			,diBtn_Active = 54
			,diBtn_Archive = 55
			,diBtn_All = 56
			,diBtn_Bin = 57
			,diBtn_Canceled = 58
			,diBtn_New = 59
			,diBtn_Terminated = 60
			,diBtn_Finished = 61
			,diBtn_InEditing = 62
			,diBtn_Classified = 63
			,diBtn_Unclassified = 64
			,diBtn_Past = 65
			,diBtn_RegenerateSettings = 66
	;


	static function init()
	{
		if (self::$data)
			return;

		$langId = Application::$userLanguageCode;
		$fn = self::$path.'/'.self::$baseFileName.'.'.$langId.'.data';
		$strData = file_get_contents($fn);
		self::$data = unserialize($strData);
	}

	static function text($id)
	{
		self::init();
		return self::$data[$id];
	}

	static function es($id)
	{
		self::init();
		return utils::es(self::$data[$id]);
	}
}
