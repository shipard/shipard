class ShipardServer {
	httpServerRoot = '';

	init ()
	{
	}

	httpHeaders ()
	{
		var headers = {};
		//headers['e10-client-type'] = e10.clientType;
		headers['e10-device-id'] = deviceId;
		headers['content-type'] = 'application/json';

		return headers;
	}

	post (url, data, f, errorFunction)
	{
		var fullUrl = this.httpServerRoot + url;

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

		//$.ajax(options);
	}

	postForm = function (url, data, f)
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

		$.ajax(options);
	}

	get = function (url, f, errorFunction)
	{
		var fullUrl = this.httpServerRoot + url;

		var options = {
			type: "GET",
			url: fullUrl,
			success: f,
			dataType: 'json',
			data: "",
			headers: this.httpHeaders (),
			error: (errorFunction != 'undefined') ? errorFunction : function (data) {
				console.log("========================ERROR: "+fullUrl);
			}
		};

		$.ajax(options);
	}

	setHttpServerRoot (httpServerRoot)
	{
		this.httpServerRoot = httpServerRoot;
	}
}




