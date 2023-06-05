class vindiPix extends HTMLElement {

  constructor() {
    // establish prototype chain
    super();

    this.dialog = document.getElementById('vindi-pix-dialog');
  }

  showDialog() {
    this.dialog?.showModal();
  }

}

customElements.define('vindi-pix', vindiPix);