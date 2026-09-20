// Plugin de BootstrapVueNext (registros: orchestrator, registry, rtl, defaults). Módulo aparte para que los entrypoints que no importan componentes
// (login) no arrastren `platform/bootstrap/index.js`.
import { createBootstrap } from 'bootstrap-vue-next/plugins';

/** Plugin de aplicación (registros, RTL, valores por defecto). No registra componentes: se importan explícitamente. */
export const bootstrapPlugin = createBootstrap({ components: {} });
