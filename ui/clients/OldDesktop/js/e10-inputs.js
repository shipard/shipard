function e10FormCheckScannerInput(element, event)
{
	var s = element.val();

	//s.replace(/./g,function(match) {return (match=="#")?"":" ";})

	s = s.replace(/\+/g, "1");
	s = s.replace(/ě/g, "2");
	s = s.replace(/š/g, "3");
	s = s.replace(/č/g, "4");
	s = s.replace(/ř/g, "5");
	s = s.replace(/ž/g, "6");
	s = s.replace(/ý/g, "7");
	s = s.replace(/á/g, "8");
	s = s.replace(/í/g, "9");
	s = s.replace(/é/g, "0");

	element.val(s);
}

function e10FormsLocalSidebar (e)
{
	var sidebarType = e.attr ('data-sidebar-local');

	var formId = searchObjectId (e, 'form');
	if (!formId)
		formId = searchObjectId (e, 'wizard');
	var sidebar = $("#" + formId + 'Sidebar');

	sidebar.attr ('data-sidebar-local-column-target', e.attr('id'));
	sidebar.find ('*').detach();

	if (sidebarType === 'calendar')
		e10FormsLocalSidebarCalendar (e, sidebar);
	else
	if (sidebarType === 'color')
		e10FormsLocalSidebarColor (e, sidebar);
}


function e10FormsDoSidebarSetVal (e, event)
{
	var sidebar = searchObjectAttr(e, 'data-sidebar-local-column-target');
	if (sidebar === null)
		return;

	var targetInputId = sidebar.attr ('data-sidebar-local-column-target');
	var input = $('#'+targetInputId);

	if (input.attr ('readonly') !== undefined)
		return;

	var value = '';

	var functionValue = e.attr('data-function-value');
	var encodedValue = e.attr('data-b64-value');
	if (functionValue !== undefined)
	{
		value = window[functionValue](e, b64DecodeUnicode(encodedValue));
	}
	else
	if (encodedValue !== undefined) {
		value = b64DecodeUnicode(encodedValue);
	}
	else
		value = e.attr('data-value');

	if (input.is ('textarea'))
	{
		if (input.hasClass('e10-inputCode'))
		{
			input.data ('cm').replaceSelection(value);
			input.data ('cm').focus();
		}
		else
		{
			input.insertAtCaret(value);
			input.focus();
		}

	}
	else
		input.val(value).focus();

	e10FormSetAsModified (input);

	if (input.hasClass ('e10-inputColor'))
		e10FormsBlurColorInput (event, input);
}


function webTextArticleImageSelected(e, codeBegin)
{
	var viewer = searchObjectAttr(e, 'data-object');


	var code = '';

	var imagesId = [];

	var formElements = viewer.find ('input');
	for (var i = 0; i < formElements.length; i++)
	{
		var element = formElements [i];
		var type = element.type;
		if (type === "checkbox")
		{
			if (element.checked)
				imagesId.push (element.value);
		}
	}

	code += codeBegin+'id:';
	for (i = 0; i < imagesId.length; i++) {
		if (i)
			code += ',';
		code += imagesId[i];
	}
	code += '}}';

	return code;
}


function e10FormsLocalSidebarCalendar (e, sidebar)
{
	var calendarCode = '';

	var date = new Date();
	date.setDate(-1);


	calendarCode += "<div class='e10-sidebar-local e10-sidebar-calendar'>";



	calendarCode += calendarMonthCode(date.getFullYear(), date.getMonth() + 1);

	date.setDate(32);
	calendarCode += calendarMonthCode(date.getFullYear(), date.getMonth() + 1);

	date.setDate(32);
	calendarCode += calendarMonthCode(date.getFullYear(), date.getMonth() + 1);

	date.setDate(32);
	calendarCode += calendarMonthCode(date.getFullYear(), date.getMonth() + 1);

	date.setDate(32);
	calendarCode += calendarMonthCode(date.getFullYear(), date.getMonth() + 1);

	calendarCode += "</div>";

	sidebar.html (calendarCode);
}


function calendarMonthCode(forYear, forMonth)
{
	var c = '';

	var date = new Date(forYear, forMonth - 1, 1);
	var months = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

	while(1)
	{
		if (date.getDay() !== 1)
			date = addDays(date, -1);

		if (date.getDay() === 1)
			break;
	}

	var month = date.getMonth();
	var year = date.getFullYear();

	var monthTitle = months[forMonth-1]+' '+forYear;

	var todayDate = new Date();
	var todayDateValue = calendarMonthCodeDayVal(todayDate);

	c += '<table class="e10-cal-small"><tbody><tr class="monthName"><td colspan="8">'+monthTitle+'</td></tr>';
	c += '<tr class="monthName"><td>&nbsp;</td><td>Po</td><td>Út</td><td>St</td><td>Čt</td><td>Pá</td><td>So</td><td>Ne</td></tr>';

	var dayClass = '';
	var dateValue = '';
	var dd = 0;
	while (1)
	{
		c += '<tr>';
		c += '<td class="week">' + getISOWeek(date) + '</td>';
		for (dd = 0; dd < 7; dd++)
		{
			dateValue = calendarMonthCodeDayVal(date);

			dayClass = 'day e10-sidebar-setval';
			if ((date.getMonth() + 1) !== forMonth)
				dayClass += ' inactive';
			else
			if (todayDateValue === dateValue)
				dayClass += ' e10-row-this e10-bold';
			else
			if (date.getMonth() === todayDate.getMonth())
				dayClass += ' e10-row-plus';

			c += '<td class="'+dayClass+'" data-value="' + dateValue + '">' + date.getDate() + '</td>';

			date = addDays(date, 1);
		}
		c += '</tr>';

		if ((date.getMonth() + 1) != forMonth)
			break;
	}

	c += '</tbody></table>';

	return c;
}


function calendarMonthCodeDayVal (currentDate)
{
	var dateValue = '';

	var d = currentDate.getDate();
	if (d < 10)
		dateValue += '0';
	dateValue += d;

	dateValue += '.';
	var m = currentDate.getMonth() + 1;
	if (m < 10)
		dateValue += '0';
	dateValue += m;

	dateValue += '.';
	var y = currentDate.getFullYear();
	dateValue += y;

	return dateValue;
}

function e10FormsBlurDateInput (event, e)
{
	var text = e.val();
	if (text === '')
		return;
	var today = new Date();

	if (text === '.' || text === ',')
		text = today.getDate() + '.' + (today.getMonth()+1) + '.' + today.getFullYear();
	else if (text.charAt(0) === '+' || text.charAt(0) === '-')
	{
		var days = parseInt(text);
		if (days) {
			today = addDays(today, days);
			text = today.getDate() + '.' + (today.getMonth() + 1) + '.' + today.getFullYear()
		}
	}
	else if (text.length === 4) {
		text = text.substring(0, 2) + '.' + text.substring(2, 4);
	}
	else if (text.length === 6)
		text = text.substring(0,2)+'.'+text.substring(2,4)+'.'+text.substring(4,6);

	text = text.replace(/^[^\d]*|[^\d]*$/gi, '');
	var parts = text.split(/[^0-9]/);

	if (parts.length === 1)
	{
		parts.push(today.getMonth() + 1);
		parts.push(today.getFullYear());
	}
	else 	if (parts.length === 2)
	{
		parts.push(today.getFullYear());
	}

	var y = parseInt(parts[2]);
	if (y && y < 100)
		parts[2] = 2000 + y;

	var dateStr = parts[2]+'-'+parts[1]+'-'+parts[0];
	if (dateIsValid(dateStr))
	{
		dateStr = pad(parts[0], 2)+'.'+pad(parts[1], 2)+'.'+parts[2];
		e.val (dateStr);
		e.removeClass ('e10-invalid');
	}
	else
	{
		e.addClass ('e10-invalid');
	}
}









function e10FormsLocalSidebarColor (e, sidebar)
{
	var sidebarCode = '';
	sidebarCode += "<div class='e10-sidebar-local e10-sidebar-color'>";
	sidebarCode += "<div class='e10-color-table'>";

	var title = '';
	var c ='';
	for (var i = 0; i < sidebarColorsTable.length; i++)
	{
		var item = sidebarColorsTable[i];

		title = '#'+item['rgb'] + ' ' + item['name'];
		c += "<span class='e10-sidebar-setval' style='background-color:#"+item['rgb']+"' data-value='#"+item['rgb']+"' title='"+title+"'></span>";
	}

	sidebarCode += c;
	sidebarCode += '</div>';

	sidebar.html (sidebarCode);
}

function e10FormsBlurColorInput (event, e)
{
	var text = e.val();

	if (text !== '')
	{
		var matchColors = /^#[0-9a-f]{6}$|^#[0-9a-f]{3}$/i;
		var match = matchColors.exec(text);
		if (match === null) {
			e.addClass('e10-invalid');
			return;
		}
	}

	e.removeClass ('e10-invalid');

	var colorBox = e.parent().find('span');
	if (text === '') {
		colorBox.css('background','#FFF');
		return;
	}
	colorBox.css('background',text);
}


var sidebarColorsTable = [
{"rgb": "000000", "name": "Black"},
{"rgb": "0C090A", "name": "Night"},
{"rgb": "2C3539", "name": "Gunmetal"},
{"rgb": "2B1B17", "name": "Midnight"},
{"rgb": "34282C", "name": "Charcoal"},
{"rgb": "25383C", "name": "Dark Slate Grey"},
{"rgb": "3B3131", "name": "Oil"},
{"rgb": "413839", "name": "Black Cat"},
{"rgb": "3D3C3A", "name": "Iridium"},
{"rgb": "463E3F", "name": "Black Eel"},
{"rgb": "4C4646", "name": "Black Cow"},
{"rgb": "504A4B", "name": "Gray Wolf"},
{"rgb": "565051", "name": "Vampire Gray"},
{"rgb": "5C5858", "name": "Gray Dolphin"},
{"rgb": "625D5D", "name": "Carbon Gray"},
{"rgb": "666362", "name": "Ash Gray"},
{"rgb": "6D6968", "name": "Cloudy Gray"},
{"rgb": "726E6D", "name": "Smokey Gray"},
{"rgb": "736F6E", "name": "Gray"},
{"rgb": "837E7C", "name": "Granite"},
{"rgb": "848482", "name": "Battleship Gray"},
{"rgb": "B6B6B4", "name": "Gray Cloud"},
{"rgb": "D1D0CE", "name": "Gray Goose"},
{"rgb": "E5E4E2", "name": "Platinum"},
{"rgb": "BCC6CC", "name": "Metallic Silver"},
{"rgb": "98AFC7", "name": "Blue Gray"},
{"rgb": "6D7B8D", "name": "Light Slate Gray"},
{"rgb": "657383", "name": "Slate Gray"},
{"rgb": "616D7E", "name": "Jet Gray"},
{"rgb": "646D7E", "name": "Mist Blue"},
{"rgb": "566D7E", "name": "Marble Blue"},
{"rgb": "737CA1", "name": "Slate Blue"},
{"rgb": "4863A0", "name": "Steel Blue"},
{"rgb": "2B547E", "name": "Blue Jay"},
{"rgb": "2B3856", "name": "Dark Slate Blue"},
{"rgb": "151B54", "name": "Midnight Blue"},
{"rgb": "000080", "name": "Navy Blue"},
{"rgb": "342D7E", "name": "Blue Whale"},
{"rgb": "15317E", "name": "Lapis Blue"},
{"rgb": "151B8D", "name": "Denim Dark Blue"},
{"rgb": "0000A0", "name": "Earth Blue"},
{"rgb": "0020C2", "name": "Cobalt Blue"},
{"rgb": "0041C2", "name": "Blueberry Blue"},
{"rgb": "2554C7", "name": "Sapphire Blue"},
{"rgb": "1569C7", "name": "Blue Eyes"},
{"rgb": "2B60DE", "name": "Royal Blue"},
{"rgb": "1F45FC", "name": "Blue Orchid"},
{"rgb": "6960EC", "name": "Blue Lotus"},
{"rgb": "736AFF", "name": "Light Slate Blue"},
{"rgb": "357EC7", "name": "Windows Blue"},
{"rgb": "368BC1", "name": "Glacial Blue Ice"},
{"rgb": "488AC7", "name": "Silk Blue"},
{"rgb": "3090C7", "name": "Blue Ivy"},
{"rgb": "659EC7", "name": "Blue Koi"},
{"rgb": "87AFC7", "name": "Columbia Blue"},
{"rgb": "95B9C7", "name": "Baby Blue"},
{"rgb": "728FCE", "name": "Light Steel Blue"},
{"rgb": "2B65EC", "name": "Ocean Blue"},
{"rgb": "306EFF", "name": "Blue Ribbon"},
{"rgb": "157DEC", "name": "Blue Dress"},
{"rgb": "1589FF", "name": "Dodger Blue"},
{"rgb": "6495ED", "name": "Cornflower Blue"},
{"rgb": "6698FF", "name": "Sky Blue"},
{"rgb": "38ACEC", "name": "Butterfly Blue"},
{"rgb": "56A5EC", "name": "Iceberg"},
{"rgb": "5CB3FF", "name": "Crystal Blue"},
{"rgb": "3BB9FF", "name": "Deep Sky Blue"},
{"rgb": "79BAEC", "name": "Denim Blue"},
{"rgb": "82CAFA", "name": "Light Sky Blue"},
{"rgb": "82CAFF", "name": "Day Sky Blue"},
{"rgb": "A0CFEC", "name": "Jeans Blue"},
{"rgb": "B7CEEC", "name": "Blue Angel"},
{"rgb": "B4CFEC", "name": "Pastel Blue"},
{"rgb": "C2DFFF", "name": "Sea Blue"},
{"rgb": "C6DEFF", "name": "Powder Blue"},
{"rgb": "AFDCEC", "name": "Coral Blue"},
{"rgb": "ADDFFF", "name": "Light Blue"},
{"rgb": "BDEDFF", "name": "Robin Egg Blue"},
{"rgb": "CFECEC", "name": "Pale Blue Lily"},
{"rgb": "E0FFFF", "name": "Light Cyan"},
{"rgb": "EBF4FA", "name": "Water"},
{"rgb": "F0F8FF", "name": "AliceBlue"},
{"rgb": "F0FFFF", "name": "Azure"},
{"rgb": "CCFFFF", "name": "Light Slate"},
{"rgb": "93FFE8", "name": "Light Aquamarine"},
{"rgb": "9AFEFF", "name": "Electric Blue"},
{"rgb": "7FFFD4", "name": "Aquamarine"},
{"rgb": "00FFFF", "name": "Cyan or Aqua"},
{"rgb": "7DFDFE", "name": "Tron Blue"},
{"rgb": "57FEFF", "name": "Blue Zircon"},
{"rgb": "8EEBEC", "name": "Blue Lagoon"},
{"rgb": "50EBEC", "name": "Celeste"},
{"rgb": "4EE2EC", "name": "Blue Diamond"},
{"rgb": "81D8D0", "name": "Tiffany Blue"},
{"rgb": "92C7C7", "name": "Cyan Opaque"},
{"rgb": "77BFC7", "name": "Blue Hosta"},
{"rgb": "78C7C7", "name": "Northern Lights Blue"},
{"rgb": "48CCCD", "name": "Medium Turquoise"},
{"rgb": "43C6DB", "name": "Turquoise"},
{"rgb": "46C7C7", "name": "Jellyfish"},
{"rgb": "7BCCB5", "name": "Blue green"},
{"rgb": "43BFC7", "name": "Macaw Blue Green"},
{"rgb": "3EA99F", "name": "Light Sea Green"},
{"rgb": "3B9C9C", "name": "Dark Turquoise"},
{"rgb": "438D80", "name": "Sea Turtle Green"},
{"rgb": "348781", "name": "Medium Aquamarine"},
{"rgb": "307D7E", "name": "Greenish Blue"},
{"rgb": "5E7D7E", "name": "Grayish Turquoise"},
{"rgb": "4C787E", "name": "Beetle Green"},
{"rgb": "008080", "name": "Teal"},
{"rgb": "4E8975", "name": "Sea Green"},
{"rgb": "78866B", "name": "Camouflage Green"},
{"rgb": "848b79", "name": "Sage Green"},
{"rgb": "617C58", "name": "Hazel Green"},
{"rgb": "728C00", "name": "Venom Green"},
{"rgb": "667C26", "name": "Fern Green"},
{"rgb": "254117", "name": "Dark Forest Green"},
{"rgb": "306754", "name": "Medium Sea Green"},
{"rgb": "347235", "name": "Medium Forest Green"},
{"rgb": "437C17", "name": "Seaweed Green"},
{"rgb": "387C44", "name": "Pine Green"},
{"rgb": "347C2C", "name": "Jungle Green"},
{"rgb": "347C17", "name": "Shamrock Green"},
{"rgb": "348017", "name": "Medium Spring Green"},
{"rgb": "4E9258", "name": "Forest Green"},
{"rgb": "6AA121", "name": "Green Onion"},
{"rgb": "4AA02C", "name": "Spring Green"},
{"rgb": "41A317", "name": "Lime Green"},
{"rgb": "3EA055", "name": "Clover Green"},
{"rgb": "6CBB3C", "name": "Green Snake"},
{"rgb": "6CC417", "name": "Alien Green"},
{"rgb": "4CC417", "name": "Green Apple"},
{"rgb": "52D017", "name": "Yellow Green"},
{"rgb": "4CC552", "name": "Kelly Green"},
{"rgb": "54C571", "name": "Zombie Green"},
{"rgb": "99C68E", "name": "Frog Green"},
{"rgb": "89C35C", "name": "Green Peas"},
{"rgb": "85BB65", "name": "Dollar Bill Green"},
{"rgb": "8BB381", "name": "Dark Sea Green"},
{"rgb": "9CB071", "name": "Iguana Green"},
{"rgb": "B2C248", "name": "Avocado Green"},
{"rgb": "9DC209", "name": "Pistachio Green"},
{"rgb": "A1C935", "name": "Salad Green"},
{"rgb": "7FE817", "name": "Hummingbird Green"},
{"rgb": "59E817", "name": "Nebula Green"},
{"rgb": "57E964", "name": "Stoplight Go Green"},
{"rgb": "64E986", "name": "Algae Green"},
{"rgb": "5EFB6E", "name": "Jade Green"},
{"rgb": "00FF00", "name": "Green"},
{"rgb": "5FFB17", "name": "Emerald Green"},
{"rgb": "87F717", "name": "Lawn Green"},
{"rgb": "8AFB17", "name": "Chartreuse"},
{"rgb": "6AFB92", "name": "Dragon Green"},
{"rgb": "98FF98", "name": "Mint Green"},
{"rgb": "B5EAAA", "name": "Green Thumb"},
{"rgb": "C3FDB8", "name": "Light Jade"},
{"rgb": "CCFB5D", "name": "Tea Green"},
{"rgb": "B1FB17", "name": "Green Yellow"},
{"rgb": "BCE954", "name": "Slime Green"},
{"rgb": "EDDA74", "name": "Goldenrod"},
{"rgb": "EDE275", "name": "Harvest Gold"},
{"rgb": "FFE87C", "name": "Sun Yellow"},
{"rgb": "FFFF00", "name": "Yellow"},
{"rgb": "FFF380", "name": "Corn Yellow"},
{"rgb": "FFFFC2", "name": "Parchment"},
{"rgb": "FFFFCC", "name": "Cream"},
{"rgb": "FFF8C6", "name": "Lemon Chiffon"},
{"rgb": "FFF8DC", "name": "Cornsilk"},
{"rgb": "F5F5DC", "name": "Beige"},
{"rgb": "FBF6D9", "name": "Blonde"},
{"rgb": "FAEBD7", "name": "AntiqueWhite"},
{"rgb": "F7E7CE", "name": "Champagne"},
{"rgb": "FFEBCD", "name": "BlanchedAlmond"},
{"rgb": "F3E5AB", "name": "Vanilla"},
{"rgb": "ECE5B6", "name": "Tan Brown"},
{"rgb": "FFE5B4", "name": "Peach"},
{"rgb": "FFDB58", "name": "Mustard"},
{"rgb": "FFD801", "name": "Rubber Ducky Yellow"},
{"rgb": "FDD017", "name": "Bright Gold"},
{"rgb": "EAC117", "name": "Golden brown"},
{"rgb": "F2BB66", "name": "Macaroni and Cheese"},
{"rgb": "FBB917", "name": "Saffron"},
{"rgb": "FBB117", "name": "Beer"},
{"rgb": "FFA62F", "name": "Cantaloupe"},
{"rgb": "E9AB17", "name": "Bee Yellow"},
{"rgb": "E2A76F", "name": "Brown Sugar"},
{"rgb": "DEB887", "name": "BurlyWood"},
{"rgb": "FFCBA4", "name": "Deep Peach"},
{"rgb": "C9BE62", "name": "Ginger Brown"},
{"rgb": "E8A317", "name": "School Bus Yellow"},
{"rgb": "EE9A4D", "name": "Sandy Brown"},
{"rgb": "C8B560", "name": "Fall Leaf Brown"},
{"rgb": "D4A017", "name": "Orange Gold"},
{"rgb": "C2B280", "name": "Sand"},
{"rgb": "C7A317", "name": "Cookie Brown"},
{"rgb": "C68E17", "name": "Caramel"},
{"rgb": "B5A642", "name": "Brass"},
{"rgb": "ADA96E", "name": "Khaki"},
{"rgb": "C19A6B", "name": "Camel brown"},
{"rgb": "CD7F32", "name": "Bronze"},
{"rgb": "C88141", "name": "Tiger Orange"},
{"rgb": "C58917", "name": "Cinnamon"},
{"rgb": "AF9B60", "name": "Bullet Shell"},
{"rgb": "AF7817", "name": "Dark Goldenrod"},
{"rgb": "B87333", "name": "Copper"},
{"rgb": "966F33", "name": "Wood"},
{"rgb": "806517", "name": "Oak Brown"},
{"rgb": "827839", "name": "Moccasin"},
{"rgb": "827B60", "name": "Army Brown"},
{"rgb": "786D5F", "name": "Sandstone"},
{"rgb": "493D26", "name": "Mocha"},
{"rgb": "483C32", "name": "Taupe"},
{"rgb": "6F4E37", "name": "Coffee"},
{"rgb": "835C3B", "name": "Brown Bear"},
{"rgb": "7F5217", "name": "Red Dirt"},
{"rgb": "7F462C", "name": "Sepia"},
{"rgb": "C47451", "name": "Orange Salmon"},
{"rgb": "C36241", "name": "Rust"},
{"rgb": "C35817", "name": "Red Fox"},
{"rgb": "C85A17", "name": "Chocolate"},
{"rgb": "CC6600", "name": "Sedona"},
{"rgb": "E56717", "name": "Papaya Orange"},
{"rgb": "E66C2C", "name": "Halloween Orange"},
{"rgb": "F87217", "name": "Pumpkin Orange"},
{"rgb": "F87431", "name": "Construction Cone Orange"},
{"rgb": "E67451", "name": "Sunrise Orange"},
{"rgb": "FF8040", "name": "Mango Orange"},
{"rgb": "F88017", "name": "Dark Orange"},
{"rgb": "FF7F50", "name": "Coral"},
{"rgb": "F88158", "name": "Basket Ball Orange"},
{"rgb": "F9966B", "name": "Light Salmon"},
{"rgb": "E78A61", "name": "Tangerine"},
{"rgb": "E18B6B", "name": "Dark Salmon"},
{"rgb": "E77471", "name": "Light Coral"},
{"rgb": "F75D59", "name": "Bean Red"},
{"rgb": "E55451", "name": "Valentine Red"},
{"rgb": "E55B3C", "name": "Shocking Orange"},
{"rgb": "FF0000", "name": "Red"},
{"rgb": "FF2400", "name": "Scarlet"},
{"rgb": "F62217", "name": "Ruby Red"},
{"rgb": "F70D1A", "name": "Ferrari Red"},
{"rgb": "F62817", "name": "Fire Engine Red"},
{"rgb": "E42217", "name": "Lava Red"},
{"rgb": "E41B17", "name": "Love Red"},
{"rgb": "DC381F", "name": "Grapefruit"},
{"rgb": "C34A2C", "name": "Chestnut Red"},
{"rgb": "C24641", "name": "Cherry Red"},
{"rgb": "C04000", "name": "Mahogany"},
{"rgb": "C11B17", "name": "Chilli Pepper"},
{"rgb": "9F000F", "name": "Cranberry"},
{"rgb": "990012", "name": "Red Wine"},
{"rgb": "8C001A", "name": "Burgundy"},
{"rgb": "954535", "name": "Chestnut"},
{"rgb": "7E3517", "name": "Blood Red"},
{"rgb": "8A4117", "name": "Sienna"},
{"rgb": "7E3817", "name": "Sangria"},
{"rgb": "800517", "name": "Firebrick"},
{"rgb": "810541", "name": "Maroon"},
{"rgb": "7D0541", "name": "Plum Pie"},
{"rgb": "7E354D", "name": "Velvet Maroon"},
{"rgb": "7D0552", "name": "Plum Velvet"},
{"rgb": "7F4E52", "name": "Rosy Finch"},
{"rgb": "7F5A58", "name": "Puce"},
{"rgb": "7F525D", "name": "Dull Purple"},
{"rgb": "B38481", "name": "Rosy Brown"},
{"rgb": "C5908E", "name": "Khaki Rose"},
{"rgb": "C48189", "name": "Pink Bow"},
{"rgb": "C48793", "name": "Lipstick Pink"},
{"rgb": "E8ADAA", "name": "Rose"},
{"rgb": "ECC5C0", "name": "Rose Gold"},
{"rgb": "EDC9AF", "name": "Desert Sand"},
{"rgb": "FDD7E4", "name": "Pig Pink"},
{"rgb": "FCDFFF", "name": "Cotton Candy"},
{"rgb": "FFDFDD", "name": "Pink Bubblegum"},
{"rgb": "FBBBB9", "name": "Misty Rose"},
{"rgb": "FAAFBE", "name": "Pink"},
{"rgb": "FAAFBA", "name": "Light Pink"},
{"rgb": "F9A7B0", "name": "Flamingo Pink"},
{"rgb": "E7A1B0", "name": "Pink Rose"},
{"rgb": "E799A3", "name": "Pink Daisy"},
{"rgb": "E38AAE", "name": "Cadillac Pink"},
{"rgb": "F778A1", "name": "Carnation Pink"},
{"rgb": "E56E94", "name": "Blush Red"},
{"rgb": "F660AB", "name": "Hot Pink"},
{"rgb": "FC6C85", "name": "Watermelon Pink"},
{"rgb": "F6358A", "name": "Violet Red"},
{"rgb": "F52887", "name": "Deep Pink"},
{"rgb": "E45E9D", "name": "Pink Cupcake"},
{"rgb": "E4287C", "name": "Pink Lemonade"},
{"rgb": "F535AA", "name": "Neon Pink"},
{"rgb": "FF00FF", "name": "Magenta"},
{"rgb": "E3319D", "name": "Dimorphotheca Magenta"},
{"rgb": "F433FF", "name": "Bright Neon Pink"},
{"rgb": "D16587", "name": "Pale Violet Red"},
{"rgb": "C25A7C", "name": "Tulip Pink"},
{"rgb": "CA226B", "name": "Medium Violet Red"},
{"rgb": "C12869", "name": "Rogue Pink"},
{"rgb": "C12267", "name": "Burnt Pink"},
{"rgb": "C25283", "name": "Bashful Pink"},
{"rgb": "C12283", "name": "Dark Carnation Pink"},
{"rgb": "B93B8F", "name": "Plum"},
{"rgb": "7E587E", "name": "Viola Purple"},
{"rgb": "571B7E", "name": "Purple Iris"},
{"rgb": "583759", "name": "Plum Purple"},
{"rgb": "4B0082", "name": "Indigo"},
{"rgb": "461B7E", "name": "Purple Monster"},
{"rgb": "4E387E", "name": "Purple Haze"},
{"rgb": "614051", "name": "Eggplant"},
{"rgb": "5E5A80", "name": "Grape"},
{"rgb": "6A287E", "name": "Purple Jam"},
{"rgb": "7D1B7E", "name": "Dark Orchid"},
{"rgb": "A74AC7", "name": "Purple Flower"},
{"rgb": "B048B5", "name": "Medium Orchid"},
{"rgb": "6C2DC7", "name": "Purple Amethyst"},
{"rgb": "842DCE", "name": "Dark Violet"},
{"rgb": "8D38C9", "name": "Violet"},
{"rgb": "7A5DC7", "name": "Purple Sage Bush"},
{"rgb": "7F38EC", "name": "Lovely Purple"},
{"rgb": "8E35EF", "name": "Purple"},
{"rgb": "893BFF", "name": "Aztech Purple"},
{"rgb": "8467D7", "name": "Medium Purple"},
{"rgb": "A23BEC", "name": "Jasmine Purple"},
{"rgb": "B041FF", "name": "Purple Daffodil"},
{"rgb": "C45AEC", "name": "Tyrian Purple"},
{"rgb": "9172EC", "name": "Crocus Purple"},
{"rgb": "9E7BFF", "name": "Purple Mimosa"},
{"rgb": "D462FF", "name": "Heliotrope Purple"},
{"rgb": "E238EC", "name": "Crimson"},
{"rgb": "C38EC7", "name": "Purple Dragon"},
{"rgb": "C8A2C8", "name": "Lilac"},
{"rgb": "E6A9EC", "name": "Blush Pink"},
{"rgb": "E0B0FF", "name": "Mauve"},
{"rgb": "C6AEC7", "name": "Wisteria Purple"},
{"rgb": "F9B7FF", "name": "Blossom Pink"},
{"rgb": "D2B9D3", "name": "Thistle"},
{"rgb": "E9CFEC", "name": "Periwinkle"},
{"rgb": "EBDDE2", "name": "Lavender Pinocchio"},
{"rgb": "E3E4FA", "name": "Lavender blue"},
{"rgb": "FDEEF4", "name": "Pearl"},
{"rgb": "FFF5EE", "name": "SeaShell"},
{"rgb": "FEFCFF", "name": "Milk White"},
{"rgb": "FFFFFF", "name": "White"}
];




$(document).ready(function(){
	jQuery.fn.extend({
		insertAtCaret: function(myValue){
			return this.each(function(i) {
				if (document.selection) {
					//For browsers like Internet Explorer
					this.focus();
					var sel = document.selection.createRange();
					sel.text = myValue;
					this.focus();
				}
				else if (this.selectionStart || this.selectionStart == '0') {
					//For browsers like Firefox and Webkit based
					var startPos = this.selectionStart;
					var endPos = this.selectionEnd;
					var scrollTop = this.scrollTop;
					this.value = this.value.substring(0, startPos)+myValue+this.value.substring(endPos,this.value.length);
					this.focus();
					this.selectionStart = startPos + myValue.length;
					this.selectionEnd = startPos + myValue.length;
					this.scrollTop = scrollTop;
				} else {
					this.value += myValue;
					this.focus();
				}
			});
		}
	});
});

