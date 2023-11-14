class ShipardServer {
	httpServerRoot = '';

	init ()
	{
	}

	beginUrl ()
	{
		/*
		if (e10.server.remote)
			return 'https://' + e10.server.remote;
		*/
		return this.httpServerRoot;
	}

	httpHeaders ()
	{
		var headers = {};
		//headers['e10-client-type'] = e10.clientType;
		//headers['e10-device-id'] = deviceId;
		headers['content-type'] = 'application/json';

		return headers;
	}

	get (url, f, errorFunction, isFullUrl)
	{
		var fullUrl = this.httpServerRoot + url;
		if (isFullUrl)
			fullUrl = url;

		var options = {
			method: "GET",
			url: fullUrl,
			headers: this.httpHeaders (),
		};

		fetch(fullUrl, options)
			.then((response) => response.json())
			.then((data) => {
				f(data);
			})
			.catch((error) => {
				console.error("Error:", error);
			});
	}

	post (url, data, f, errorFunction)
	{
		var fullUrl = this.httpServerRoot + url;
		//console.log('server.post: ', fullUrl);
		var options = {
			method: 'POST',
			url: fullUrl,
			body: JSON.stringify(data),
			dataType: 'json',
			headers: this.httpHeaders (),
			error: (errorFunction != 'undefined') ? errorFunction : function (data) {
				console.log("========================ERROR: "+fullUrl);
			}
		};


		fetch(fullUrl, options)
			.then((response) => response.json())
			.then((data) => {
				console.log("Success:", data);
				f(data);
			})
			.catch((error) => {
				console.error("Error:", error);
			});
	}


	api (data, f, errorFunction)
	{
		var fullUrl = this.beginUrl() + 'api';

		var options = {
			method: 'POST',
			url: fullUrl,
			body: JSON.stringify(data),
			dataType: 'json',
			headers: this.httpHeaders (),
			error: (errorFunction != 'undefined') ? errorFunction : function (data) {
				console.log("========================ERROR: "+fullUrl);
			}
		};

		fetch(fullUrl, options)
			.then((response) => response.json())
			.then((data) => {
				console.log("Success:", data);
				f(data);
			})
			.catch((error) => {
				console.error("Error:", error);
			});
	}

	postForm (url, data, f)
	{
		var fullUrl = this.httpServerRoot + url;

		var options = {
			type: 'POST',
			url: fullUrl,
			success: f,
			data: data,
			//dataType: 'json',
			headers: this.httpHeaders (),
			error: function (data) {
				console.log("========================ERROR: "+fullUrl);
			}
		};

		//$.ajax(options);
	}

	setHttpServerRoot (httpServerRoot)
	{
		this.httpServerRoot = httpServerRoot;
	}
}

