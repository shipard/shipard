function searchParentWithClass (e, className)
{
	var p = e;
	while (p.length)
	{
		if (p.hasClass (className))
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

