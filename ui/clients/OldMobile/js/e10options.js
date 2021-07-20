e10client.prototype.options = {
};

e10client.prototype.options.get = function (key, defaultValue)
{
	var value = localStorage.getItem('options-'+key);

	if (value === null)
		return defaultValue;

	return value;
};

e10client.prototype.options.set = function (key, value)
{
	localStorage.setItem('options-'+key, value);
};


e10client.prototype.options.openDialog = function ()
{
	e10.printbt.scan();

	var tc = "<span class='lmb e10-trigger-action' data-action='form-close'><i class='fa fa-times'></i></span>" +
			"<div class='pageTitle'><h1>Nastavení aplikace</h1><h2>Shipard</h2></div>" +
			"<ul class='rb'><li class='e10-trigger-action' data-action='app-options-save'><i class='fa fa-check'></i> Hotovo</li></ul>";

	var cc = '';
	//cc += "<div class='e10-option-row'><label>Sdílet polohu</label><input type='checkbox' class='e10-inputLogical' name='shareLocation' value='1'></div>";
	//cc += "<div class='e10-option-row'><label>Používat Bluetooth</label><input type='checkbox' class='e10-inputLogical' name='useBluetooth' value='1'></div>";


	cc += "<div class='e10-option-row'><label>Způsob tisku účtenek</label><select name='receiptsLocalPrintMode'>";
	cc += "<option value='none'>-- nenastavovat --</option>";

	if (e10.printbt.supported)
		cc += "<option value='bt'>Bluetooth</option>";

	cc += "<option value='lan'>Síť (WiFi)</option>";
	cc += "</select></div>";

	if (e10.printbt.supported) {
		cc += "<div class='e10-option-row'><label>Bluetooth tiskárna</label><select name='receiptsPrinterBluetooth'>";
		cc += "<option value=''>Nenastaveno</option>";

		for (var printerId in e10.printbt.printers) {
			var printerName = e10.printbt.printers [printerId];
			console.log ("#P "+printerId+" - "+printerName);
			cc += "<option value='" + printerId + "'>" + printerName + "</option>";
		}

		cc += "</select></div>";
	}

	cc += "<div class='e10-option-row'><label>IP adresa tiskárny</label><br><input type='string' class='e10-inputString' name='receiptsPrinterLan'></div>";

	cc += "<div class='e10-option-row'><label>Typ tiskárny</label><select name='receiptsLocalPrinterType'>";
	cc += "<option value='normal'>Klasická (78mm)</option>";
	cc += "<option value='thin'>Úzká (55mm)</option>";
	cc += "</select></div>";

	cc += "<div class='e10-option-row'><label>Používat jako Terminál</label><input type='checkbox' class='e10-inputLogical' name='useTerminalMode' value='1'></div>";
	cc += "<div class='e10-option-row'><label>URL terminálu</label><br><input type='url' class='e10-inputString' name='terminalURL'></div>";

	var data = {
		toolbarCode: tc,
		contentCode: cc,
		formData: {
			recData: {
				//shareLocation: e10.options.get ('shareLocation', 0),
				receiptsLocalPrintMode: e10.options.get ('receiptsLocalPrintMode', 'none'),
				receiptsPrinterBluetooth: e10.options.get ('receiptsPrinterBluetooth', ''),
				receiptsPrinterLan: e10.options.get ('receiptsPrinterLan', ''),
				receiptsLocalPrinterType: e10.options.get ('receiptsLocalPrinterType', 'normal'),
				//useBluetooth: e10.options.get ('useBluetooth', 0),
				useTerminalMode: e10.options.get ('useTerminalMode', 0),
				terminalURL: e10.options.get ('terminalURL', '')
			}
		}
	};

	e10.form.create (data, 'app-options');
};


e10client.prototype.options.saveDialog = function (e)
{
	var form = e10.searchObjectAttr(e, 'data-object');

	var postData = {};
	postData.formData = e10.form.getData(form);

	//e10.options.set ('shareLocation', postData.formData.recData.shareLocation);
	e10.options.set ('receiptsLocalPrintMode', postData.formData.recData.receiptsLocalPrintMode);
	e10.options.set ('receiptsPrinterBluetooth', postData.formData.recData.receiptsPrinterBluetooth);
	e10.options.set ('receiptsPrinterLan', postData.formData.recData.receiptsPrinterLan);
	e10.options.set ('receiptsLocalPrinterType', postData.formData.recData.receiptsLocalPrinterType);
	//e10.options.set ('useBluetooth', postData.formData.recData.useBluetooth);
	e10.options.set ('useTerminalMode', postData.formData.recData.useTerminalMode);
	e10.options.set ('terminalURL', postData.formData.recData.terminalURL);

	window.location.reload();
	e10.options.apply();
	return 1;
};


e10client.prototype.options.apply = function (e)
{
	// -- bluetooth
	var useBluetooth = e10.options.get ('useBluetooth', 0);
	if (useBluetooth == 1)
		e10.bt.on ();
	else
		e10.bt.off ();

	// -- share location
	var shareLocation = e10.options.get ('shareLocation', 0);
	if (shareLocation == 1)
		e10.geo.on ();
	else
		e10.geo.off ();
};

e10client.prototype.options.fontSize = function (e, how) {
	var cfs = $('html').css('font-size');
	var cfsNumber = parseInt(cfs, 10);

	if (how === 0)
	{ // reset
		$('html').css ('font-size', 'medium');
		e10.options.set ('appFontSize', null);
	}
	else
	{
		cfsNumber += how;
		$('html').css ('font-size', cfsNumber+'px');
		e10.options.set ('appFontSize', cfsNumber);
	}
	e10.refreshLayout();
};

e10client.prototype.options.loadAppSettings = function () {
	var fontSize = e10.options.get ('appFontSize');
	if (fontSize)
		$('html').css ('font-size', fontSize+'px');

};

e10client.prototype.options.openAppMenuDialog = function ()
{
	var appType = $('body').attr ('data-app-type');

	var tc = '';


	if (e10.userInfo) {
		tc = "<span class='lmb'><i class='fa fa-user'></i></span>" +
				"<div class='pageTitle'>";
		tc += "<h1>" + e10.escapeHtml(e10.userInfo.name) + "</h1>";
		tc += "<h2>" + e10.escapeHtml(e10.userInfo.login) + "</h2>";
		tc += "<ul class='rb'><li class='e10-trigger-action' data-action='form-close'><i class='fa fa-times'></i></li></ul>";
		tc += "</div>";
	}
	else
	{
		tc = "<span class='lmb'><i class='fa fa-wrench'></i></span>" +
				"<div class='pageTitle'>";
		tc += "<h1>" + e10.escapeHtml('Nastavení aplikace') + "</h1>";
		tc += "<h2>" + e10.escapeHtml(' ') + "</h2>";
		tc += "<ul class='rb'><li class='e10-trigger-action' data-action='form-close'><i class='fa fa-times'></i></li></ul>";
		tc += "</div>";
	}

	var cc = '';

	if (1)
	{
		cc += "<div class='block'>";
		cc += "<h3>Velikost písma</h3>";
		cc += "<div class='e10-option-fsbtn e10-trigger-action' data-action='app-fs-plus'><i class='fa fa-plus'></i><span>Větší</span></div>";
		cc += "<div class='e10-option-fsbtn e10-trigger-action' data-action='app-fs-minus'><i class='fa fa-minus'></i><span>Menší</span></div>";
		cc += "<div class='e10-option-fsbtn e10-trigger-action' data-action='app-fs-reset'><i class='fa fa-asterisk'></i><span>Výchozí</span></div>";

		cc += "<div class='font-size-example'>";
		cc += "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.";
		cc += "</div>";

		cc += "</div>";

	}


	if (e10.app)
	{
		cc += "<div class='block'>";
		cc += "<div class='e10-option-bigbtn e10-trigger-action' data-action='app-options'><i class='fa fa-cog'></i><span>Nastavení</span></div>";
		cc += "<div class='e10-option-bigbtn e10-trigger-action' data-action='app-logout'><i class='fa fa-power-off'></i><span>Odhlásit</span></div>";
		cc += "</div>";
	}

	var data = {
		toolbarCode: tc,
		contentCode: cc,
		formData: {
			recData: {
			}
		}
	};

	e10.form.create (data, 'app-menu');
};

e10client.prototype.options.appAbout = function (e) {
	var infoBox = e.find ('>div.info');

	if (!infoBox.is(":visible"))
	{
		var info = "<div style='border-top: 1px solid gray; padding-top: .5rem;'>";

		var systemInfo = e10.systemInfo();

		for (var i in systemInfo) {
			var item = systemInfo[i];
			info += '<p><b>'+e10.escapeHtml(item.title)+'</b><br><small>'+e10.escapeHtml(item.value)+'</small></p>';
		}

		info += '</div>';

		infoBox.html(info);
		infoBox.show();
	}
	else
	{
		infoBox.hide();
	}
};
