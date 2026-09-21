// BSkeletonImg de PRODEX (Vue 3 puro): marcador de carga con el marcado y las clases de BootstrapVue 2 (`b-aspect` 16:9 + `.b-skeleton.b-skeleton-img`
// con `b-skeleton-animate-wave|fade|throb|cylon`), para conservar tamaño, relación de aspecto, animación, radio y el salto de maquetación (ninguno).
// BootstrapVueNext solo ofrece `BPlaceholder` (otro marcado y otra animación). El CSS `.b-skeleton*` / `.b-aspect*` sigue siendo el de bootstrap-vue.css
// hasta la fase 5C.
import { h } from 'vue';
import { pure } from './core.js';

const toSize = (value) => (typeof value === 'number' ? `${value}px` : value);

export const BSkeletonImg = /*#__PURE__*/ pure({
  name: 'BSkeletonImg',
  inheritAttrs: false,
  props: {
    animation: { type: String, default: 'wave' },
    aspect: { type: String, default: '16:9' },
    noAspect: { type: Boolean, default: false },
    width: { type: [String, Number], default: undefined },
    height: { type: [String, Number], default: undefined },
    cardImg: { type: String, default: undefined },
  },
  setup(props, { attrs }) {
    return () => {
      const { class: klass, style, ...rest } = attrs;
      const img = h('div', {
        ...rest,
        class: ['b-skeleton', 'b-skeleton-img', props.animation ? `b-skeleton-animate-${props.animation}` : null, props.cardImg ? `card-img-${props.cardImg}` : null, klass],
        style: [style, { width: toSize(props.width), height: toSize(props.height) }],
      });
      if (props.noAspect) return img;
      const [w, hh] = String(props.aspect).split(':').map(Number);
      const ratio = w > 0 && hh > 0 ? (hh / w) * 100 : 56.25;
      return h('div', { class: 'b-aspect d-flex' }, [
        h('div', { class: 'b-aspect-sizer flex-grow-1', style: { paddingBottom: `${ratio}%`, height: 0 } }),
        h('div', { class: 'b-aspect-content flex-grow-1 w-100 mw-100', style: { marginLeft: '-100%' } }, [img]),
      ]);
    };
  },
});
