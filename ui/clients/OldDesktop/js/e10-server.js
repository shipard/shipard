e10client.prototype.server = {
	deviceInfoSent: 0,
	remote: null
};


e10client.prototype.server.init = function () {

};

e10client.prototype.server.beginUrl = function () {
	if (e10.server.remote)
		return 'https://' + e10.server.remote;
	return e10.httpServerRoot;
};




e10client.prototype.server.httpHeaders = function () {
	var headers = {};

	headers['e10-client-type'] = e10.clientType;
	//headers['e10-device-id'] = e10.deviceId;

	if (e10.oldBrowser)
		headers['e10-old-browser'] = '1';

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

	if (!e10.server.deviceInfoSent)
	{
		e10.server.deviceInfoSent = 1;
		headers['e10-device-info'] = btoa(JSON.stringify(e10.systemInfo(true)));
	}

	if (e10.server.remote)
		headers['e10-remote'] = e10.server.remote;

	return headers;
};

e10client.prototype.server.api = function (data, f, errorFunction) {
	var fullUrl = e10.server.beginUrl() + '/api';

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

	if (e10.server.remote !== null)
	{
		options.xhrFields =  {
			withCredentials: true
		};
		options.crossDomain = true;
	}

	e10.server.remote = null;
	$.ajax(options);
};


e10client.prototype.server.post = function (url, data, f, errorFunction) {
	var fullUrl = e10.server.beginUrl() + url;

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

	if (e10.server.remote !== null)
	{
		options.xhrFields =  {
			withCredentials: true
		};
		options.crossDomain = true;
	}

	e10.server.remote = null;
	$.ajax(options);
};

e10client.prototype.server.postForm = function (url, data, f) {
	var fullUrl = e10.server.beginUrl() + url;

	var options = {
		type: 'POST',
		url: fullUrl,
		success: f,
		data: data,
		dataType: 'json',
		headers: e10.server.httpHeaders (),
		error: function (data) {
			console.log("========================ERROR: "+fullUrl);
			console.log(data);
		}
	};

	if (e10.server.remote !== null)
	{
		options.xhrFields =  {
			withCredentials: true
		};
		options.crossDomain = true;
	}

	e10.server.remote = null;
	$.ajax(options);
};

e10client.prototype.server.get = function (url, f, errorFunction) {
	var fullUrl = (url.startsWith('https://') ? url : e10.server.beginUrl() + url);

	var options = {
		type: "GET",
		url: fullUrl,
		success: f,
		dataType: 'json',
		data: "",
		headers: e10.server.httpHeaders ()
	};

	if (errorFunction !== undefined)
		options.error = errorFunction;
	else
		options.error = function (data) {console.log("========================ERROR-GET: "+fullUrl);};
	
	if (e10.server.remote !== null || url.startsWith('https://'))
	{
		options.xhrFields =  {
			withCredentials: true
		};
		options.crossDomain = true;
	}

	e10.server.remote = null;
	$.ajax(options);
};


e10client.prototype.server.setHttpServerRoot = function (httpServerRoot) {
	e10.httpServerRoot = httpServerRoot;
};

e10client.prototype.server.setRemote = function (e) {
	if (e.attr('data-remote'))
		e10.server.remote = e.attr('data-remote');
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
