var e10client = function () {
	this.userInfo = null;
};

e10client.appVersion = '2.0.1';

e10client.prototype = {
};

e10client.prototype.executeFunctionByName = function (functionName/*, args */) {
	var context = window;
	var args = [].slice.call(arguments).splice(1);
	var namespaces = functionName.split(".");
	var func = namespaces.pop();
	for(var i = 0; i < namespaces.length; i++) {
		context = context[namespaces[i]];
	}
	return context[func].apply(this, args);
};


function b64DecodeUnicode(str) {
	return decodeURIComponent(Array.prototype.map.call(atob(str), function(c) {
		return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
	}).join(''));
}

function dateIsValid(s) {
	var bits = s.split('-');
	var y = bits[0],
		m = bits[1],
		d = bits[2];
	var daysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

	if ((!(y % 4) && y % 100) || !(y % 400)) {
		daysInMonth[1] = 29;
	}
	return !(/\D/.test(String(d))) && d > 0 && d <= daysInMonth[--m];
}

function pad(a,b){return(1e15+a+"").slice(-b);}

function addDays(date, amount) {
	var tzOff = date.getTimezoneOffset() * 60 * 1000,
		t = date.getTime(),
		d = new Date(),
		tzOff2;

	t += (1000 * 60 * 60 * 24) * amount;
	d.setTime(t);

	tzOff2 = d.getTimezoneOffset() * 60 * 1000;
	if (tzOff != tzOff2) {
		var diff = tzOff2 - tzOff;
		t += diff;
		d.setTime(t);
	}

	return d;
}

function getISOWeek (forDate)
{
	var date = new Date(forDate.getTime());
	date.setHours(0, 0, 0, 0);
	// Thursday in current week decides the year.
	date.setDate(date.getDate() + 3 - (date.getDay() + 6) % 7);
	// January 4 is always in week 1.
	var week1 = new Date(date.getFullYear(), 0, 4);
	// Adjust to Thursday in week 1 and count number of weeks from date to week1.
	return 1 + Math.round(((date.getTime() - week1.getTime()) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
}

function searchObjectAttr (e, attr)
{
	var p = e;
	while (p.length)
	{
		if (p.attr (attr))
			return p;

		p = p.parent ();
		if (!p.length)
			break;
	}

	return null;
}

function elementPrefixedAttributes (e, prefix, data)
{
	var iel = e.get(0);
	for (var i = 0, attrs = iel.attributes, l = attrs.length; i < l; i++)
	{
		var attrName = attrs.item(i).nodeName;
		if (attrName.substring(0, prefix.length) !== prefix)
			continue;
		var attrNameShort = attrName.substring(prefix.length);
		var val = attrs.item(i).nodeValue;
		data[attrNameShort] = val;
	}
}

if (!String.prototype.startsWith) {
	Object.defineProperty(String.prototype, 'startsWith', {
		value: function(search, rawPos) {
			var pos = rawPos > 0 ? rawPos|0 : 0;
			return this.substring(pos, pos + search.length) === search;
		}
	});
}
