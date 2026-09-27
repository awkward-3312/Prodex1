# Evidencia de la auditoría de modernización frontend

Scripts de solo lectura usados para producir las cifras de `../FRONTEND_MODERNIZATION_AUDIT.md`.
Ninguno modifica el repositorio ni se ejecuta como parte del build.

| Script | Qué mide | Cómo se ejecutó |
|---|---|---|
| `scan-vue2-patterns.js` | Patrones Vue 2 por archivo (`$set`, `slot-scope`, `$listeners`, `Fire`, `$bvModal`, etc.) y tamaño de cada `.vue`/`.js` de `resources/src` | `node scan-vue2-patterns.js <dir-salida>` |
| `scan-routes.js` | Registros de ruta, lazy loading, redirects, guards, meta (AST con `@babel/parser`) | `node scan-routes.js resources/src/router.js resources/src/portal/router.js` |
| `compat-spike-*.js` | Spike de `@vue/compat` 3.5.13 (modo 2) + BootstrapVue 2.23.1 + vue-router 3.6.5 + Vuex 3.6.2 + vue-i18n 8.28.2 + otras librerías, sobre jsdom | En un directorio temporal FUERA del repo, con `vue@npm:@vue/compat@3.5.13` (ver "Cómo repetir el spike" en la auditoría) |

Los scripts asumen rutas absolutas de la máquina donde se ejecutaron; ajústalas antes de reutilizarlos.
