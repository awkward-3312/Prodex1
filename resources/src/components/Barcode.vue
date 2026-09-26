<template>
  <div>
    <component :is="elementTag" :style="{ display: valid ? undefined : 'none' }" class="vue-barcode-element" />
    <div :style="{ display: valid ? 'none' : undefined }"><slot /></div>
  </div>
</template>

<script>
// Reemplaza `vue-barcode` (wrapper Vue 2 sobre JsBarcode, `render(createElement)`): envuelve `jsbarcode` directo
// con las mismas props/comportamiento — incluido el fallback al slot por defecto cuando el código no es válido
// (`valid`, actualizado por el callback de la propia librería). Registrado como `barcode` (mismo nombre de tag),
// así ninguna de las 6 vistas que lo consumen cambia.
import JsBarcode from 'jsbarcode';

export default {
  name: 'Barcode',
  props: {
    value: { type: [String, Number], default: undefined },
    format: { type: String, default: undefined },
    width: { type: [String, Number], default: undefined },
    height: { type: [String, Number], default: undefined },
    displayValue: { type: [String, Boolean], default: true },
    text: { type: [String, Number], default: undefined },
    fontOptions: { type: String, default: undefined },
    font: { type: String, default: undefined },
    textAlign: { type: String, default: undefined },
    textPosition: { type: String, default: undefined },
    textMargin: { type: [String, Number], default: undefined },
    fontSize: { type: [String, Number], default: undefined },
    background: { type: String, default: undefined },
    lineColor: { type: String, default: undefined },
    margin: { type: [String, Number], default: undefined },
    marginTop: { type: [String, Number], default: undefined },
    marginBottom: { type: [String, Number], default: undefined },
    marginLeft: { type: [String, Number], default: undefined },
    marginRight: { type: [String, Number], default: undefined },
    flat: { type: Boolean, default: undefined },
    ean128: { type: [String, Boolean], default: undefined },
    elementTag: {
      type: String,
      default: 'svg',
      validator: (value) => ['canvas', 'svg', 'img'].includes(value),
    },
  },
  data() {
    return { valid: true };
  },
  mounted() {
    this.render();
  },
  updated() {
    this.render();
  },
  methods: {
    render() {
      const settings = {
        format: this.format,
        width: this.width,
        height: this.height,
        displayValue: this.displayValue,
        text: this.text,
        fontOptions: this.fontOptions,
        font: this.font,
        textAlign: this.textAlign,
        textPosition: this.textPosition,
        textMargin: this.textMargin,
        fontSize: this.fontSize,
        background: this.background,
        lineColor: this.lineColor,
        margin: this.margin,
        marginTop: this.marginTop,
        marginBottom: this.marginBottom,
        marginLeft: this.marginLeft,
        marginRight: this.marginRight,
        flat: this.flat,
        ean128: this.ean128,
        valid: (valid) => { this.valid = valid; },
        elementTag: this.elementTag,
      };
      Object.keys(settings).forEach((key) => { if (settings[key] === undefined) delete settings[key]; });
      const el = this.$el.querySelector('.vue-barcode-element');
      if (el) JsBarcode(el, String(this.value), settings);
    },
  },
};
</script>
