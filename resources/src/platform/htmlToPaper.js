// Reemplaza `vue-html-to-paper` (plugin Vue 2, `Vue.prototype.$htmlToPaper`) por un helper directo, misma firma y
// comportamiento exacto (ver `node_modules/vue-html-to-paper/build/vue-html-to-paper.js`): abre una ventana,
// escribe el `innerHTML` del elemento, añade los `<link rel="stylesheet">` de `styles`, espera 1000ms, enfoca e
// imprime, y cierra la ventana 1ms después si `autoClose`. `makeHtmlToPaper(options)` fija las opciones por
// defecto (igual que `Vue.use(VueHtmlToPaper, options)`); el resultado se asigna a `Vue.prototype.$htmlToPaper`
// tal cual el plugin original — bajo `@vue/compat` eso sigue llegando a `this.$htmlToPaper` en cada componente,
// igual que `$uploadPath`/`$imgUrl` (ver `main.js`).

function addStyles(win, styles) {
  styles.forEach((style) => {
    const link = win.document.createElement('link');
    link.setAttribute('rel', 'stylesheet');
    link.setAttribute('type', 'text/css');
    link.setAttribute('href', style);
    win.document.getElementsByTagName('head')[0].appendChild(link);
  });
}

function openWindow(url, name, props) {
  const windowRef = window.open(url, name, props);
  if (!windowRef.opener) windowRef.opener = self;
  windowRef.focus();
  return windowRef;
}

export function makeHtmlToPaper(options = {}) {
  return (el, localOptions, cb = () => true) => {
    let name = options.name || '_blank';
    let specs = options.specs || ['fullscreen=yes', 'titlebar=yes', 'scrollbars=yes'];
    let styles = options.styles || [];
    let autoClose = options.autoClose !== undefined ? options.autoClose : true;
    let windowTitle = options.windowTitle || window.document.title;

    if (localOptions) {
      if (localOptions.name) name = localOptions.name;
      if (localOptions.specs) specs = localOptions.specs;
      if (localOptions.styles) styles = localOptions.styles;
      if (localOptions.autoClose === false) autoClose = localOptions.autoClose;
      if (localOptions.windowTitle) windowTitle = localOptions.windowTitle;
    }

    const specsStr = specs.length ? specs.join(',') : '';
    const element = window.document.getElementById(el);
    if (!element) {
      alert(`Element to print #${el} not found!`);
      return false;
    }

    const win = openWindow('', name, specsStr);
    win.document.write(`
      <html>
        <head>
          <title>${windowTitle || window.document.title}</title>
        </head>
        <body>
          ${element.innerHTML}
        </body>
      </html>
    `);
    addStyles(win, styles);

    setTimeout(() => {
      win.focus();
      win.print();
      if (autoClose) setTimeout(() => { win.close(); }, 1);
      cb();
    }, 1000);

    return true;
  };
}
