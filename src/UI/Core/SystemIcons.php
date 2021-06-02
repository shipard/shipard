<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionAddWizard = 1
		,actionBack = 2
		,actionCalendar = 3
		,actionClose = 4
		,actionCopy = 5
		,actionDatabaseName = 6
		,actionDelete = 7
		,actionDownload = 8
		,actionExpandClose = 9
		,actionExpandOpen = 10
		,actionHomePage = 11
		,actionInputClear = 12
		,actionInputMinus = 13
		,actionInputPlus = 14
		,actionInputSearch = 15
		,actionLogout = 16
		,actionMoveDown = 17
		,actionMoveUp = 18
		,actionNotifications = 19
		,actionOpen = 20
		,actionPrint = 21
		,actionRegenerate = 22
		,actionSave = 23
		,actionSupport = 24
		,actionWizardDone = 25
		,actionWizardNext = 26
		,brandsGoogle = 27
		,dashboardDashboard = 28
		,dashboardModeRows = 29
		,dashboardModeTilesBig = 30
		,dashboardModeTilesSmall = 31
		,dashboardModeViewer = 32
		,detailAccounting = 33
		,detailAnalysis = 34
		,detailBalance = 35
		,detailCalculate = 36
		,detailDetail = 37
		,detailHistory = 38
		,detailInfo = 39
		,detailLinks = 40
		,detailMovement = 41
		,detailNotes = 42
		,detailOverview = 43
		,detailRecipients = 44
		,detailReport = 45
		,detailReservations = 46
		,detailRows = 47
		,detailSettings = 48
		,detailStock = 49
		,detailUsage = 50
		,docStateArchive = 51
		,docStateCancel = 52
		,docStateConcept = 53
		,docStateConfirmed = 54
		,docStateDelete = 55
		,docStateDone = 56
		,docStateEdit = 57
		,docStateHalfDone = 58
		,docStateNew = 59
		,docStateUnknown = 60
		,filterActive = 61
		,filterAll = 62
		,filterArchive = 63
		,filterDone = 64
		,filterOverview = 65
		,filterTrash = 66
		,formAccounting = 67
		,formAttachments = 68
		,formFilter = 69
		,formHeader = 70
		,formHistory = 71
		,formNote = 72
		,formNotes = 73
		,formRows = 74
		,formSettings = 75
		,formSorting = 76
		,iconAdmin = 77
		,iconAppInfoMenu = 78
		,iconBalance = 79
		,iconBug = 80
		,iconDatabase = 81
		,iconEmail = 82
		,iconFile = 83
		,iconFilePdf = 84
		,iconHamburgerMenu = 85
		,iconHelp = 86
		,iconHistory = 87
		,iconImage = 88
		,iconImport = 89
		,iconInbox = 90
		,iconKeyboard = 91
		,iconLaboratory = 92
		,iconLocalServer = 93
		,iconLocked = 94
		,iconOther = 95
		,iconOwner = 96
		,iconPhone = 97
		,iconPhoto = 98
		,iconPinned = 99
		,iconPreview = 100
		,iconReaders = 101
		,iconReports = 102
		,iconSearch = 103
		,iconSettings = 104
		,iconSpinner = 105
		,iconStart = 106
		,iconTerminal = 107
		,iconUser = 108
		,iconVideo = 109
		,iconViewerEnd = 110
		,iconWarning = 111
		,iconWorkplace = 112
		,issueAdvertisementSPAM = 113
		,issueAlert = 114
		,issueBoardNote = 115
		,issueCall = 116
		,issueComment = 117
		,issueDiscussion = 118
		,issueMeeting = 119
		,issueNote = 120
		,issueReceivedMail = 121
		,issueSentMail = 122
		,issueSystemControl = 123
		,issueTask = 124
		,issueToDo = 125
		,leftSubmenuBulkMail = 126
		,personCompany = 127
		,personHuman = 128
		,personRobot = 129
		,rightSubmenuAccess = 130
		,rightSubmenuCalendar = 131
		,rightSubmenuCanteen = 132
		,rightSubmenuCompartments = 133
		,rightSubmenuDocuments = 134
		,rightSubmenuMeters = 135
		,rightSubmenuNews = 136
		,rightSubmenuNoticeBoard = 137
		,rightSubmenuReceivables = 138
		,rightSubmenuReservations = 139
		,rightSubmenuSupport = 140
		,rightSubmenuToDo = 141
	;


		public function systemIcon(int $i)
		{
			if (!$this->data)
			{
				$this->data = unserialize(file_get_contents(__SHPD_ROOT_DIR__ . 'ui/icons/'.$this->iconsId.'/system-icons-map.data'));
			}
	
			return $this->data[$i];
		}
		}
