e10client.prototype.form = {
	gnOptions: null,
	gnValue: '',
	gnDisplay: null,
	gnId: '',

	cvId: '',
	cvValue: 0,
	cvOptions: null,
};


e10client.prototype.form.create = function (data, id) {
	var c = "<div class='e10-form' id='" + id + "' data-object='form' data-classid='" + data.classId + "'>";
	c += "<div class='e10-form-content'>";
	c += "<div class='toolbar'></div>";
	c += "<div class='content'>";
	c += "<form onsubmit='return e10.form.submit(this);' action='javascript:void(0);'></form>";
	c += "</div>";
	c += "</div>";
	c += "</div>";

	$('body').append(c);


	var form = $('#' + id);
	if (data.formData.recData.ndx !== undefined)
		form.attr('data-pk', data.formData.recData.ndx);
	else
		form.attr('data-pk', '0');

	form.css('width', '100%');
	form.css('height', '100%');
	form.css('overflow-y', 'auto');

	var content = form.find('div.content>form');
	var toolbar = form.find('div.toolbar');

	toolbar.html(data.toolbarCode);
	content.html(data.contentCode + "<input type='submit' value='Submit Button' id='sbmt' style='display: none;' />");

	e10.form.setData(form, data.formData);

	return 0;
};


e10client.prototype.form.close = function (e) {
	var form = e10.searchObjectAttr(e, 'data-object');
	form.detach();

	return 0;
};


e10client.prototype.form.open = function (e) {
	e10.setProgress(1);

	var classId = e.attr("data-classid");

	e10.g_formId++;
	var newElementId = "mainEditF" + e10.g_formId;

	var url = "/api/f/" + classId + "?newFormId=" + newElementId;

	if (e.attr('data-addparams') !== undefined)
		url += '&'+ e.attr('data-addparams');

	if (e.attr('data-pict'))
		url += '&addPicture=' + e.attr('data-pict');
	if (e.attr('data-pict-thumb'))
		url += '&addPictureThumbnail=' + e.attr('data-pict-thumb');

	var postData = {};
	postData.operation = e.attr('data-operation');
	postData.pk = e.attr('data-pk');

	e10.server.post(url, postData, function (data) {
		e10.form.create(data, newElementId);
		e10.setProgress(0);
	});
};


e10client.prototype.form.done = function (e) {
	var form = e10.searchObjectAttr(e, 'data-object');

	var htmlForm = form.find('>div.e10-form-content>div.content>form');

	$('#sbmt').click();

	if (!htmlForm[0].checkValidity())
		return;

	e10.setProgress(1);

	var classId = form.attr('data-classid');

	var postData = {};
	postData.operation = 'done';
	postData.pk = form.attr('data-pk');
	postData.formData = e10.form.getData(form);

	var url = "/api/f/" + classId;

	e10.server.post(url, postData, function (data) {
		e10.form.uploadFiles (form, data.table, data.pk);

		form.detach();
		e10.setProgress(0);

		e10.loadPage('');
	});

	return 1;
};

e10client.prototype.form.submit = function (form) {
	return false;
};


e10client.prototype.form.getData = function (form) {
	var newData = {};
	var usedInputs = new Array();
	var thisInputValue = null;
	var mainFid = form.attr('id');

	var formElements = form.find('input, select, textarea');
	for (var i = 0; i < formElements.length; i++) {
		var thisInput = $(formElements [i]);
		if (!thisInput.attr("name") && !thisInput.attr("data-name"))
			continue;
		//if (thisInput.attr ("data-fid") !== mainFid)
		//	continue;
		var thisInputName = thisInput.attr('name');
		if (thisInput.attr("data-name"))
			thisInputName = thisInput.attr('data-name');

		var dataMainPart = 'recData';
		var dataSubPart = null;
		var dataRowPart = null;
		var dataColumnPart = null;

		var nameParts = thisInputName.split('.');
		if (nameParts.length == 1)
			dataColumnPart = thisInputName;
		else if (nameParts.length == 2) {
			dataMainPart = nameParts [0];
			dataColumnPart = nameParts [1];
		}
		else if (nameParts.length == 3) {
			dataMainPart = nameParts [0];
			dataColumnPart = nameParts [1];
			dataRowPart = nameParts [2];
		}
		else if (nameParts.length == 4) {
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataRowPart = nameParts [2];
			dataColumnPart = nameParts [3];
		}

		thisInputValue = null;

		if (thisInput.hasClass("e10-inputLogical")) {
			if (thisInput.attr('value') == 'Y') {
				if (thisInput.is(':checked'))
					thisInputValue = 'Y';
				else
					thisInputValue = 'N';
			}
			else if (thisInput.attr('value') != '1') {
				if (thisInput.is(':checked'))
					thisInputValue = thisInput.attr('value');
				else
					thisInputValue = '0';
			}
			else {
				if (thisInput.is(':checked'))
					thisInputValue = 1;
				else
					thisInputValue = 0;
			}
		}
		else if (thisInput.hasClass("e10-inputRadio")) {
			if (thisInput.is(':checked'))
				thisInputValue = thisInput.attr('value');
			else
				continue;
		}
		else if (thisInput.hasClass("e10-inputDate")) {
			if (thisInput.val() != '') {
				var dp = thisInput.val().split(".");
				thisInputValue = dp [2] + "-" + dp [1] + "-" + dp [0];

				if (thisInput.hasClass("e10-inputDateTime")) {
					var timeInput = $('#' + thisInput.attr('id') + '_Time');
					thisInputValue += ' ' + timeInput.val();
				}
				// native input type: thisInputValue = thisInput.val ();
			}
			else
				thisInputValue = '0000-00-00';
		}
		else if (thisInput.hasClass("e10-inputMoney") || thisInput.hasClass("e10-inputDouble") || thisInput.hasClass("e10-inputInt")) {
			if (thisInput.val() != '') {
				var sv = thisInput.val();
				sv = sv.replace(",", ".").replace(/\s/g, '');
				thisInputValue = parseFloat(sv);
			}
			else
				thisInputValue = 0.0;
		}
		else if (thisInput.hasClass("e10-inputEnum")) {
			if (thisInput.hasClass("e10-inputEnumMultiple")) {
				if (thisInput.val())
					thisInputValue = thisInput.val().join('.');
				else
					thisInputValue = '';
			}
			else
				thisInputValue = thisInput.val();
		}
		else if (thisInput.hasClass("e10-inputRefId") && !thisInput.hasClass("e10-inputRefIdDirty")) {

		}
		else if (thisInput.hasClass("e10-inputDocLink")) {
			thisInputValue = {};
			var listItems = thisInput.find('ul li');
			for (var ii = 0; ii < listItems.length; ii++) {
				var li = $(listItems [ii]);
				//thisInputValue.push ({"table": li.attr ('data-table'), "pk": li.attr ('data-pk')});
				if (!thisInputValue [li.attr('data-table')])
					thisInputValue [li.attr('data-table')] = [li.attr('data-pk')];
				else
					thisInputValue [li.attr('data-table')].push(li.attr('data-pk'));
			}
		}
		else if (thisInput.hasClass("e10-inputCode")) {
			thisInputValue = thisInput.data('cm').getValue();
		}
		else
			thisInputValue = thisInput.val();

		if (thisInputValue === null)
			continue;

		if (!newData [dataMainPart])
			newData [dataMainPart] = {};

		if (dataMainPart == 'recData') {
			newData [dataMainPart][dataColumnPart] = thisInputValue;
			usedInputs.push(dataColumnPart);
		}
		else {
			if (nameParts.length == 2) {
				if (!newData [dataMainPart]/*[dataColumnPart]*/)
					newData [dataMainPart]/*[dataColumnPart]*/ = {};
				newData [dataMainPart][dataColumnPart] = thisInputValue;
			}
			else if (nameParts.length == 3) {
				if (!newData [dataMainPart][dataColumnPart])
					newData [dataMainPart][dataColumnPart] = {};
				newData [dataMainPart][dataColumnPart][dataRowPart] = thisInputValue;
			}
			else {
				if (!newData [dataMainPart][dataSubPart])
					newData [dataMainPart][dataSubPart] = {};
				if (!newData [dataMainPart][dataSubPart][dataRowPart])
					newData [dataMainPart][dataSubPart][dataRowPart] = {};
				newData [dataMainPart][dataSubPart][dataRowPart][dataColumnPart] = thisInputValue;
			}
		}
	}

	return newData;
};


e10client.prototype.form.setData = function (form, data)
{
//	e10FormSetAsSaved (id);
	var formElements = form.find ('input, select, textarea');
	var readOnly = (form.attr ('data-readonly') !== undefined);

	for (var i = 0; i < formElements.length; i++)
	{
		var thisInput = $(formElements [i]);
		if (thisInput.attr ("name") === undefined && thisInput.attr ("data-name")  === undefined)
			continue;
		var thisInputName = thisInput.attr ('name');
		if (thisInput.attr ("data-name"))
			thisInputName = thisInput.attr ('data-name');

		var dataMainPart = 'recData';
		var dataSubPart = null;
		var dataRowPart = null;
		var dataColumnPart = null;

		var nameParts = thisInputName.split ('.');
		if (nameParts.length == 1)
			dataColumnPart = thisInputName;
		else
		if (nameParts.length == 2)
		{
			dataMainPart = nameParts [0];
			dataColumnPart = nameParts [1];
		}
		else
		if (nameParts.length == 3)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataColumnPart = nameParts [2];
		}
		else
		if (nameParts.length == 4)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataRowPart = nameParts [2];
			dataColumnPart = nameParts [3];
		}
		//if (dataMainPart === 'extra')
		//	continue;

		var thisInputValue = null;
		if (dataMainPart == 'recData')
			thisInputValue = data [dataMainPart][dataColumnPart];
		else
		if (nameParts.length == 2)
			thisInputValue = data [dataMainPart][dataColumnPart];
		else
		if (nameParts.length == 3)
			thisInputValue = data [dataMainPart][dataSubPart][dataColumnPart];
		else
		{
			if ((data [dataMainPart] != undefined) && (data [dataMainPart][dataSubPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart] != undefined))
				thisInputValue = data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart];
		}

		if (thisInputValue === undefined)
			thisInputValue = '';

		if (thisInput.hasClass ("e10-fromSensor"))
		{
			var sensorId = '#e10-sensordisplay-'+thisInput.attr('data-srcsensor');
			var btnInputId = thisInput.attr ('id')+'_sensor';

			if (thisInputValue === undefined || thisInputValue == '' || thisInputValue == 0)
			{
				thisInputValue = $(sensorId).text();
				//e10FormNeedSave (thisInput, 0);
			}

			var dstelm = form;
			var sensList = dstelm.attr('data_receivesensors');
			if (sensList === undefined)
				sensList = btnInputId;
			else
				sensList = sensList + ' ' + btnInputId;
			dstelm.attr ('data_receivesensors', sensList);

			$('#'+btnInputId).text ($(sensorId).text());
		}


		if (thisInput.hasClass ("e10-inputLogical"))
		{
			var checkIt = false;

			if (thisInput.val () == '1')
			{
				if (thisInputValue == 1)
					checkIt = true;
			}
			else
			if (thisInputValue)
				checkIt = true;

			if (checkIt)
				thisInput.attr('checked', true);
			else
				thisInput.attr('checked', false);
		}
		else
		if (thisInput.hasClass ("e10-inputRadio"))
		{
			if (thisInput.attr ('value') == thisInputValue)
				thisInput.attr('checked', true);
		}
		else
		if (thisInput.hasClass ("e10-inputDate"))
		{
			var dateVal = "";
			var timeVal = "";
			var timeInput = null;
			if (thisInputValue && thisInputValue.date !== undefined)
			{
				var ds = thisInputValue.date.substring (0, 10);
				dp = ds.split ("-");
				dateVal = dp [2] + "." + dp [1] + "." + dp [0];
				if (thisInput.hasClass ("e10-inputDateTime"))
				{
					timeInput = $('#'+thisInput.attr('id')+'_Time');
					timeVal = thisInputValue.date.substring (11, 16);
				}
				// native input type: thisInput.val(ds);
			}
			thisInput.val (dateVal);

			if (timeInput)
				timeInput.val (timeVal);
		}
		else
		if (thisInput.hasClass ("e10-inputDateTime_Time"))
		{
		}
		else
		if (thisInput.hasClass ("e10-inputEnum"))
		{
			if (thisInput.hasClass ("e10-inputEnumMultiple"))
			{
				if (thisInputValue)
					thisInput.val (thisInputValue.split('.'));
				thisInput.trigger("liszt:updated");
			}
			else
				thisInput.val (thisInputValue);
		}
		else
		if (thisInput.hasClass ("e10-inputRefId") && !thisInput.hasClass ("e10-inputRefIdDirty"))
		{
		}
		else
		if (thisInput.hasClass ("e10-inputNdx"))
		{
			thisInput.val (thisInputValue);
			if (thisInputValue != 0)
				thisInput.parent().find('span.btns').show();
		}
		else
		if (thisInput.hasClass ("e10-inputDocLink"))
		{
			var inpItems = '';
			for (var ii = 0; ii < thisInputValue.length; ii++)
			{
				var li = thisInputValue[ii];
				inpItems += "<li data-pk='" + li['dstRecId'] + "' data-table='" + li['dstTableId'] + "'" + '>' +
				li['title'] +
				((readOnly) ? "&nbsp;" : "<span class='e10-inputDocLink-closeItem'>&times;</span>") +
				"</li>";
			}
			thisInput.find ('ul').html (inpItems);
			if (inpItems != '')
				thisInput.find ("span.placeholder").hide();
			else
				thisInput.find ("span.placeholder").show();
		}
		else
		if (thisInput.hasClass ("e10-inputMoney") || thisInput.hasClass ("e10-inputDouble") || thisInput.hasClass ("e10-inputInt"))
		{
			if (thisInputValue != 0)
				thisInput.val (thisInputValue);
			else
				thisInput.val ('');
		}
		else {
			thisInput.val(thisInputValue);
		}
	}
};


e10client.prototype.form.getNumber = function (options) {
	e10.form.gnId = 'gn1234';
	e10.form.gnValue = '';

	if (e10.form.gnOptions)
		delete e10.form.gnOptions;

	e10.form.gnOptions = options;

	var c = "<div class='e10-form-get-number' id='" + e10.form.gnId + "'>";
	c += "<table class='e10-get-number-keyboard'>";

	c += "<tr>";
	c += "<td class='c e10-trigger-gn'><i class='fa fa-times'></i></td><td class='m' colspan='3'>";
	if (e10.form.gnOptions.title)
		c += "<div class='title'>"+e10.escapeHtml(e10.form.gnOptions.title)+"</div>";
	if (e10.form.gnOptions.subtitle)
		c += "<div class='e10-small'>"+e10.escapeHtml(e10.form.gnOptions.subtitle)+"</div>";
	c += "</td>";
	c += "</tr>";


	c += "<tr>";
	c += "<td class='d e10-trigger-gn' colspan='3'></td><td class='b e10-trigger-gn'><i class='fa fa-arrow-circle-left'></i></td>";
	c += "</tr>";


	c += "<tr>";
	c += "<td class='n e10-trigger-gn'>7</td><td class='n e10-trigger-gn'>8</td><td class='n e10-trigger-gn'>9</td><td class='ok e10-trigger-gn' rowspan='4'><i class='fa fa-check'></i></td>";
	c += "</tr>";

	c += "<tr>";
	c += "<td class='n e10-trigger-gn'>4</td><td class='n e10-trigger-gn'>5</td><td class='n e10-trigger-gn'>6</td>";
	c += "</tr>";

	c += "<tr>";
	c += "<td class='n e10-trigger-gn'>1</td><td class='n e10-trigger-gn'>2</td><td class='n e10-trigger-gn'>3</td>";
	c += "</tr>";

	c += "<tr>";
	c += "<td class='n e10-trigger-gn' colspan='2'>0</td><td class='n e10-trigger-gn'>,</td>";
	c += "</tr>";

	c += "</table>";

	c += "</div>";

	$('body').append(c);


	var form = $('#' + e10.form.gnId);
	e10.form.gnDisplay = form.find ('td.d');
/*
	form.css('width', '100%');
	form.css('height', '100%');
	form.css('overflow-y', 'auto');
*/

	return 0;
};

e10client.prototype.form.getNumberAction = function (event, e) {

	if (e.hasClass('n'))
	{
		e10.form.gnValue += e.text();
		e10.form.gnDisplay.text (e10.form.gnValue);
	}
	else
	if (e.hasClass('b'))
	{
		if (e10.form.gnValue !== '')
		{
			e10.form.gnValue = e10.form.gnValue.slice(0, -1);
			e10.form.gnDisplay.text(e10.form.gnValue);
		}
	}
	else
	if (e.hasClass('c'))
	{
		e10.form.getNumberClose();
	}
	else
	if (e.hasClass('ok'))
	{
		e10.form.getNumberDone();
	}
};

e10client.prototype.form.getNumberClose = function (event, e) {
	var e = $('#'+e10.form.gnId);
	e.empty().detach();
};

e10client.prototype.form.getNumberDone = function (event, e) {
	e10.form.gnOptions.success();
};

e10client.prototype.form.comboViewer = function (options) {
	e10.form.cvId = 'cv1234';
	e10.form.cvValue = 0;
	e10.disableKeyDown = 1;

	if (e10.form.cvOptions)
		delete e10.form.cvOptions;

	e10.form.cvOptions = options;

	var c = "<div class='e10-form-combo-viewer' id='" + e10.form.cvId + "' style='padding: 1em;'>";
	c += "<div class='e10-form-combo-viewer-content' id='e10-form-combo-viewer-content' style='overflow-y: auto;'></div>";
	c += "</div>";

	$('body').append(c);

	var url = e10.appUrlRoot;
	url += 'comboviewer/' + options.table + '/' + options.viewer + '?app=1';

	e10.server.get (url, function (data) {
		var viewerForm = $('#' + e10.form.cvId);
		var viewerContent = $('#e10-form-combo-viewer-content');
		viewerContent.html (data.object.htmlCode);

		var viewerTitle = $('#e10-form-combo-viewer-title');
		var viewerContentHeight = viewerForm.innerHeight() - viewerTitle.height() - 25;

		var viewer = viewerContent.find('>div.e10-viewer');
		var viewerList = viewer.find ('>ul.e10-viewer-list');
		viewerList.height(viewerContentHeight);
		viewerList.get(0).onscroll = function () {e10.viewer.loadNextDataCombo();};
		var searchInput = viewerTitle.find ('input.e10-inc-search');
		searchInput.attr ('placeholder', e10.form.cvOptions.title).focus();
	}/*, errorFunction*/);

	return 0;
};


e10client.prototype.form.comboViewerRefreshLayout = function (e) {

};

e10client.prototype.form.comboViewerAction = function (event, e) {
	if (e.hasClass('c'))
	{
		e10.form.comboViewerClose();
	}
};

e10client.prototype.form.comboViewerClose = function (event, e) {
	var e = $('#'+e10.form.cvId);
	e.empty().detach();
	e10.disableKeyDown = 0;
};

e10client.prototype.form.comboViewerDone = function (e) {
	e10.form.cvOptions.success(e);
	e10.disableKeyDown = 0;
};
