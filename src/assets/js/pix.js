class vindiPix extends HTMLElement {

  constructor() {
    // establish prototype chain
    super();

    this.dialog = document.getElementById('vindi-pix-dialog');
    this.image  = this.dialog?.querySelector('.vindi-pix-dialog__image');
    this.input  = this.dialog?.querySelector('.vindi-pix-dialog__input');
    this.button = this.dialog?.querySelector('.vindi-pix-dialog__button');

    this.button?.addEventListener('click', (event) => this.copy(event));
  }

  showDialog(transaction) {
    if(!transaction)
      return;

    const response = transaction['gateway_response_fields'];

    if(this.image)
      this.image.src = response['qrcode_path'];
    if(this.input)
      this.input.value = response['qrcode_original_path'];
    
    this.dialog?.showModal();
  }

  async copy(event) {
    event.preventDefault();

    try {
        await navigator.clipboard.writeText(this.input.value);
    }
    catch(error) {
        console.log('copy error', error);
    }
  }

}

customElements.define('vindi-pix', vindiPix);