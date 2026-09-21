// Casos de la sonda BV2 vs BVN (fase 5A). Cada caso es una plantilla con los usos REALES de las vistas (auditados por AST, ver
// tests/frontend/inventory). `same: true` → el HTML normalizado debe ser idéntico; si no, `note` explica la diferencia aceptada y `allow`
// lista las clases/atributos que pueden diferir.
module.exports = [
  // ----- layout -----
  { name: 'row + col md/sm/lg', template: '<b-row><b-col lg="12" md="6" sm="12">a</b-col><b-col md="6">b</b-col></b-row>' },
  { name: 'col cols + col boolean + auto', template: '<b-row><b-col cols="12">a</b-col><b-col col>b</b-col><b-col cols="auto">c</b-col><b-col :md="4">d</b-col></b-row>' },
  { name: 'col sin props', template: '<b-row><b-col>a</b-col><b-col class="x y">b</b-col></b-row>' },
  { name: 'row no-gutters', template: '<b-row no-gutters class="r"><b-col>a</b-col></b-row>' },
  { name: 'container fluid', template: '<b-container fluid class="c"><b-row><b-col>a</b-col></b-row></b-container>' },
  { name: 'card simple', template: '<b-card class="mb-3"><b-card-text>t</b-card-text>cuerpo</b-card>' },
  { name: 'card title', template: '<b-card title="Titulo" class="c">cuerpo</b-card>' },
  { name: 'card header', template: '<b-card header="Cabecera" header-bg-variant="light">cuerpo</b-card>' },
  { name: 'card no-body + header/footer/body', template: '<b-card no-body><b-card-header>H</b-card-header><b-card-body>B</b-card-body><b-card-footer>F</b-card-footer></b-card>' },
  { name: 'card title tag', template: '<b-card><b-card-title>T</b-card-title><b-card-sub-title>S</b-card-sub-title>x</b-card>' },
  { name: 'card slot header', template: '<b-card><template #header><b>H</b></template>x</b-card>' },
  // ----- button -----
  { name: 'button variant size block', template: '<b-button variant="primary" size="sm" block class="m">Ok</b-button>' },
  { name: 'button submit disabled', template: '<b-button type="submit" variant="outline-secondary" :disabled="true">x</b-button>' },
  { name: 'button href target', template: '<b-button variant="link" href="/x" target="_blank">x</b-button>' },
  { name: 'button tag', template: '<b-button tag="a" variant="light">x</b-button>' },
  { name: 'button group', template: '<b-button-group size="sm" class="g"><b-button variant="primary">a</b-button><b-button>b</b-button></b-button-group>' },
  // ----- primitives -----
  { name: 'badge variant pill', template: '<b-badge variant="success" pill class="x">1</b-badge><b-badge :variant="v">2</b-badge><b-badge>3</b-badge>', data: { v: 'danger' } },
  { name: 'alert show variant', template: '<b-alert show variant="warning" class="a">hola</b-alert><b-alert :show="false" variant="info">no</b-alert>' },
  { name: 'alert dismissible', template: '<b-alert show dismissible variant="info">x</b-alert>' },
  { name: 'progress bar', template: '<b-progress :value="40" :max="100" height="8px" class="p"></b-progress>' },
  { name: 'progress animated show-progress', template: '<b-progress :value="60" :max="100" show-progress animated></b-progress>' },
  { name: 'progress con progress-bar', template: '<b-progress :max="10"><b-progress-bar :value="3" variant="success"></b-progress-bar><b-progress-bar :value="2" variant="danger"></b-progress-bar></b-progress>' },
  { name: 'link', template: '<b-link href="/x" class="l">x</b-link><b-link class="m" @click="hits++">y</b-link>', data: { hits: 0 } },
  { name: 'list group', template: '<b-list-group flush><b-list-group-item class="i">a</b-list-group-item><b-list-group-item>b</b-list-group-item></b-list-group>' },
  { name: 'img', template: '<b-img thumbnail fluid src="/images/x.png" alt="x" width="40" height="40"></b-img>' },
  // ----- tabs -----
  { name: 'tabs basic', template: '<b-tabs content-class="mt-3"><b-tab title="Uno" active>a</b-tab><b-tab title="Dos">b</b-tab></b-tabs>' },
  { name: 'tabs lazy pills', template: '<b-tabs pills lazy><b-tab title="Uno">a</b-tab><b-tab title="Dos" :disabled="true">b</b-tab></b-tabs>' },
  // ----- dropdown -----
  { name: 'dropdown right no-caret', template: '<b-dropdown id="dd" right no-caret variant="light" toggle-class="tc" menu-class="mc" size="sm"><template #button-content>X</template><b-dropdown-item @click="hits++">Uno</b-dropdown-item><b-dropdown-item to="/app/dashboard">Dos</b-dropdown-item></b-dropdown>', data: { hits: 0 } },
  { name: 'dropdown text split', template: '<b-dropdown id="dd2" text="Acciones" variant="primary"><b-dropdown-header>H</b-dropdown-header><b-dropdown-divider></b-dropdown-divider><b-dropdown-item>Uno</b-dropdown-item></b-dropdown>' },
  // ----- pagination -----
  { name: 'pagination', template: '<b-pagination v-model="page" :total-rows="95" :per-page="10" align="center" size="sm" class="pg"></b-pagination>', data: { page: 2 } },
];
