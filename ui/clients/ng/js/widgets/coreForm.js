class ShipardCoreForm extends ShipardWidget
{
  formData = null;

  init(e)
  {
    console.log("ShipardCoreForm::init");
    super.init(e);
  }

  setFormData(data)
  {
    this.formData = data;
    //console.log('setFormData', data);
    const inputs = this.rootElm.querySelectorAll('input, textarea, select');

    inputs.forEach(input => {
      this.setFormInputValue(input);
    });
  }

  setFormInputValue(input)
  {
    const inputId = input.getAttribute('name');
    if (!inputId)
      return;

    const iv = this.dataInputValue(inputId);
    //console.log('setFormInputValue', inputId, iv);

    if (input.classList.contains('e10-inputDateN'))
    {
      let siv = iv;
      if (iv === null || iv === '0000-00-00')
        siv = '';

      input.value = siv;
      return;
    }
    if (input.classList.contains('e10-inputLogical'))
    {
      input.checked = parseInt(iv) == 1;
      return;
    }

    //console.log('set input value ', iv, input);
    input.value = iv;
  }

  dataInputValue (inputId)
  {
    var iidParts = inputId.split ('.');

		if (iidParts.length == 1)
    {
      return this.formData['recData'][inputId] ? this.formData['recData'][inputId] : null;
    }

    return null;
  }

  getFormData()
  {
    const inputs = this.rootElm.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
      this.getFormInputValue(input);
    });
  }

  getFormInputValue(input)
  {
    const inputId = input.getAttribute('name');
    if (!inputId)
      return;

    const iv = input.value;
    //console.log('getFormInputValue', inputId, iv);

    let siv = iv;

    if (input.classList.contains('e10-inputDateN'))
    {
      if (iv === null || iv === '0000-00-00' || iv === '')
        siv = null;
    }
    else if (input.classList.contains('e10-inputLogical'))
    {
      siv = input.checked ? 1 : 0;
    }

    this.setDataInputValue(inputId, siv);
  }

  setDataInputValue (inputId, value)
  {
    var iidParts = inputId.split ('.');

		if (iidParts.length == 1)
    {
      this.formData['recData'][inputId] = value;
    }
  }
}
