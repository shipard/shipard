e10client.prototype.server = {
	deviceInfoSent: 0
};


e10client.prototype.server.init = function () {

};

e10client.prototype.server.httpHeaders = function () {
	var headers = {};

	headers['e10-client-type'] = e10.clientType;
	headers['e10-device-id'] = e10.deviceId;

	if (e10.userSID !== '')
		headers['e10-login-sid'] = e10.userSID;
	else
	if (e10.userPassword !== '')
		headers['e10-login-pw'] = e10.userPassword;
	else
	if (e10.userPin !== '')
		headers['e10-login-pin'] = e10.userPin;

	if (e10.userLogin !== '')
		headers['e10-login-user'] = e10.userLogin;

	if (e10.oldBrowser)
		headers['e10-old-browser'] = '1';

	if (!e10.server.deviceInfoSent)
	{
		e10.server.deviceInfoSent = 1;
		headers['e10-device-info'] = btoa(JSON.stringify(e10.systemInfo(true)));
	}

	return headers;
};


e10client.prototype.server.post = function (url, data, f, errorFunction) {
	var fullUrl = e10.httpServerRoot + url;

	var options = {
		type: 'POST',
		url: fullUrl,
		success: f,
		data: JSON.stringify(data),
		dataType: 'json',
		headers: e10.server.httpHeaders (),
		error: (errorFunction != 'undefined') ? errorFunction : function (data) {
			console.log("========================ERROR: "+fullUrl);
		}
	};

	$.ajax(options);
};

e10client.prototype.server.postForm = function (url, data, f) {
	var fullUrl = e10.httpServerRoot + url;

	var options = {
		type: 'POST',
		url: fullUrl,
		success: f,
		data: data,
		//dataType: 'json',
		headers: e10.server.httpHeaders (),
		error: function (data) {
			console.log("========================ERROR: "+fullUrl);
		}
	};

	$.ajax(options);
};

e10client.prototype.server.get = function (url, f, errorFunction) {
	var fullUrl = e10.httpServerRoot + url;

	var options = {
		type: "GET",
		url: fullUrl,
		success: f,
		dataType: 'json',
		data: "",
		headers: e10.server.httpHeaders (),
		error: (errorFunction != 'undefined') ? errorFunction : function (data) {
			console.log("========================ERROR: "+fullUrl);
		}
	};

	$.ajax(options);
};


e10client.prototype.server.setHttpServerRoot = function (httpServerRoot) {
	e10.httpServerRoot = httpServerRoot;
};


e10client.prototype.server.setUser = function (login, sid, pw, pin) {
	e10.userPin = '';
	e10.userSID = '';
	e10.userPassword = '';

	e10.userLogin = btoa(login);

	if (sid && sid !== '')
		e10.userSID = btoa(sid);
	if (pw && pw !== '')
		e10.userPassword = btoa(pw);
	if (pin && pin !== '')
		e10.userPin = btoa(pin);
};
