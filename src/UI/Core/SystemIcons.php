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
		,actionLogIn = 16
		,actionLogout = 17
		,actionMoveDown = 18
		,actionMoveUp = 19
		,actionNotifications = 20
		,actionOpen = 21
		,actionPlay = 22
		,actionPrint = 23
		,actionRegenerate = 24
		,actionSave = 25
		,actionSettings = 26
		,actionStop = 27
		,actionSupport = 28
		,actionUpload = 29
		,actionWizardDone = 30
		,actionWizardNext = 31
		,brandsGoogle = 32
		,dashboardDashboard = 33
		,dashboardModeRows = 34
		,dashboardModeTilesBig = 35
		,dashboardModeTilesSmall = 36
		,dashboardModeViewer = 37
		,detailAccounting = 38
		,detailAnalysis = 39
		,detailBalance = 40
		,detailCalculate = 41
		,detailDetail = 42
		,detailHistory = 43
		,detailInfo = 44
		,detailLinks = 45
		,detailMovement = 46
		,detailNotes = 47
		,detailOverview = 48
		,detailRecipients = 49
		,detailReport = 50
		,detailReservations = 51
		,detailRows = 52
		,detailSettings = 53
		,detailSources = 54
		,detailStock = 55
		,detailSubjects = 56
		,detailUsage = 57
		,docStateArchive = 58
		,docStateCancel = 59
		,docStateConcept = 60
		,docStateConfirmed = 61
		,docStateDelete = 62
		,docStateDone = 63
		,docStateEdit = 64
		,docStateHalfDone = 65
		,docStateNew = 66
		,docStateUnknown = 67
		,filterActive = 68
		,filterAll = 69
		,filterArchive = 70
		,filterDone = 71
		,filterOverview = 72
		,filterTrash = 73
		,formAccounting = 74
		,formAttachments = 75
		,formFilter = 76
		,formHeader = 77
		,formHistory = 78
		,formNote = 79
		,formNotes = 80
		,formRows = 81
		,formSettings = 82
		,formSorting = 83
		,iconAdmin = 84
		,iconAngleRight = 85
		,iconAppInfoMenu = 86
		,iconBalance = 87
		,iconBook = 88
		,iconBug = 89
		,iconCalendar = 90
		,iconCamera = 91
		,iconCheck = 92
		,iconCogs = 93
		,iconCutlery = 94
		,iconDatabase = 95
		,iconDelivery = 96
		,iconEmail = 97
		,iconFile = 98
		,iconFilePdf = 99
		,iconHamburgerMenu = 100
		,iconHelp = 101
		,iconHistory = 102
		,iconHome = 103
		,iconIdBadge = 104
		,iconImage = 105
		,iconImport = 106
		,iconInbox = 107
		,iconKeyboard = 108
		,iconLaboratory = 109
		,iconLink = 110
		,iconList = 111
		,iconLocalServer = 112
		,iconLocked = 113
		,iconMapMarker = 114
		,iconOrder = 115
		,iconOther = 116
		,iconOwner = 117
		,iconPaperPlane = 118
		,iconPhone = 119
		,iconPhoto = 120
		,iconPinned = 121
		,iconPreview = 122
		,iconReaders = 123
		,iconReports = 124
		,iconSearch = 125
		,iconSettings = 126
		,iconSitemap = 127
		,iconSpinner = 128
		,iconStar = 129
		,iconStart = 130
		,iconTerminal = 131
		,iconUser = 132
		,iconVideo = 133
		,iconViewerEnd = 134
		,iconWarning = 135
		,iconWorkplace = 136
		,issueAdvertisementSPAM = 137
		,issueAlert = 138
		,issueBoardNote = 139
		,issueCall = 140
		,issueComment = 141
		,issueConcept = 142
		,issueDiscussion = 143
		,issueImportant = 144
		,issueMeeting = 145
		,issueNotImportant = 146
		,issueNote = 147
		,issueReceivedMail = 148
		,issueSelected = 149
		,issueSentMail = 150
		,issueStandart = 151
		,issueSystemControl = 152
		,issueTask = 153
		,issueToDo = 154
		,issueUnread = 155
		,leftSubmenuBulkMail = 156
		,personCompany = 157
		,personHuman = 158
		,personRobot = 159
		,rightSubmenuAccess = 160
		,rightSubmenuCalendar = 161
		,rightSubmenuCanteen = 162
		,rightSubmenuCompartments = 163
		,rightSubmenuDocuments = 164
		,rightSubmenuMeters = 165
		,rightSubmenuNews = 166
		,rightSubmenuNoticeBoard = 167
		,rightSubmenuReceivables = 168
		,rightSubmenuReservations = 169
		,rightSubmenuSupport = 170
		,rightSubmenuToDo = 171
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
