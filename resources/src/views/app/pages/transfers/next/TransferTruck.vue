<template>
  <div class="pxtt" aria-hidden="true">
    <div class="pxtt__wrap">
      <div class="pxtt__body">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 198 93" class="pxtt__truck">
          <path class="pxtt__cab" stroke-width="3" d="M135 22.5H177.264C178.295 22.5 179.22 23.133 179.594 24.0939L192.33 56.8443C192.442 57.1332 192.5 57.4404 192.5 57.7504V89C192.5 90.3807 191.381 91.5 190 91.5H135C133.619 91.5 132.5 90.3807 132.5 89V25C132.5 23.6193 133.619 22.5 135 22.5Z"></path>
          <path class="pxtt__window" stroke-width="3" d="M146 33.5H181.741C182.779 33.5 183.709 34.1415 184.078 35.112L190.538 52.112C191.16 53.748 189.951 55.5 188.201 55.5H146C144.619 55.5 143.5 54.3807 143.5 53V36C143.5 34.6193 144.619 33.5 146 33.5Z"></path>
          <path class="pxtt__ink" stroke-width="2" d="M150 65C150 65.39 149.763 65.8656 149.127 66.2893C148.499 66.7083 147.573 67 146.5 67C145.427 67 144.501 66.7083 143.873 66.2893C143.237 65.8656 143 65.39 143 65C143 64.61 143.237 64.1344 143.873 63.7107C144.501 63.2917 145.427 63 146.5 63C147.573 63 148.499 63.2917 149.127 63.7107C149.763 64.1344 150 64.61 150 65Z"></path>
          <rect class="pxtt__light" stroke-width="2" rx="1" height="7" width="5" y="63" x="187"></rect>
          <rect class="pxtt__ink" stroke-width="2" rx="1" height="11" width="4" y="81" x="193"></rect>
          <rect class="pxtt__cargo" stroke-width="3" rx="2.5" height="90" width="121" y="1.5" x="6.5"></rect>
          <rect class="pxtt__cargo" stroke-width="2" rx="2" height="4" width="6" y="84" x="1"></rect>
        </svg>
      </div>
      <div class="pxtt__tires">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 30 30" class="pxtt__tire">
          <circle class="pxtt__tire-out" stroke-width="3" r="13.5" cy="15" cx="15"></circle>
          <circle class="pxtt__hub" r="7" cy="15" cx="15"></circle>
        </svg>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 30 30" class="pxtt__tire">
          <circle class="pxtt__tire-out" stroke-width="3" r="13.5" cy="15" cx="15"></circle>
          <circle class="pxtt__hub" r="7" cy="15" cx="15"></circle>
        </svg>
      </div>
      <div class="pxtt__road"></div>
      <svg viewBox="0 0 453.459 453.459" xmlns="http://www.w3.org/2000/svg" class="pxtt__lamp">
        <path d="M252.882,0c-37.781,0-68.686,29.953-70.245,67.358h-6.917v8.954c-26.109,2.163-45.463,10.011-45.463,19.366h9.993
  c-1.65,5.146-2.507,10.54-2.507,16.017c0,28.956,23.558,52.514,52.514,52.514c28.956,0,52.514-23.558,52.514-52.514
  c0-5.478-0.856-10.872-2.506-16.017h9.992c0-9.354-19.352-17.204-45.463-19.366v-8.954h-6.149C200.189,38.779,223.924,16,252.882,16
  c29.952,0,54.32,24.368,54.32,54.32c0,28.774-11.078,37.009-25.105,47.437c-17.444,12.968-37.216,27.667-37.216,78.884v113.914
  h-0.797c-5.068,0-9.174,4.108-9.174,9.177c0,2.844,1.293,5.383,3.321,7.066c-3.432,27.933-26.851,95.744-8.226,115.459v11.202h45.75
  v-11.202c18.625-19.715-4.794-87.527-8.227-115.459c2.029-1.683,3.322-4.223,3.322-7.066c0-5.068-4.107-9.177-9.176-9.177h-0.795
  V196.641c0-43.174,14.942-54.283,30.762-66.043c14.793-10.997,31.559-23.461,31.559-60.277C323.202,31.545,291.656,0,252.882,0z
  M232.77,111.694c0,23.442-19.071,42.514-42.514,42.514c-23.442,0-42.514-19.072-42.514-42.514c0-5.531,1.078-10.957,3.141-16.017
  h78.747C231.693,100.736,232.77,106.162,232.77,111.694z"></path>
      </svg>
    </div>
  </div>
</template>

<script>
// Camión en movimiento (referencia: Uiverse vinodjangid07/popular-owl-27). Puramente decorativo:
// no conoce datos ni API; el padre decide cuándo montarlo (solo logistics_status === 'in_transit').
export default { name: "TransferTruck" };
</script>

<style lang="scss" scoped>
.pxtt { --k: 1.25; width: fit-content; max-width: 100%; animation: pxtt-in 260ms var(--pxn-ease-out) 1 both; }

.pxtt__wrap {
  position: relative; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
  width: calc(200px * var(--k)); height: calc(100px * var(--k)); overflow-x: hidden;
}

@media (max-width: 620px) { .pxtt { --k: 1.1; } }

// suspensión
.pxtt__body { width: calc(130px * var(--k)); height: fit-content; margin-bottom: calc(6px * var(--k)); animation: pxtt-motion 1s linear infinite; }
.pxtt__truck { display: block; width: 100%; }

.pxtt__tires { position: absolute; bottom: 0; display: flex; align-items: center; justify-content: space-between; width: calc(130px * var(--k)); padding: 0 calc(10px * var(--k)) 0 calc(15px * var(--k)); }
.pxtt__tire { width: calc(24px * var(--k)); }

// paleta PRODEX (cabina = acento del tenant, estructura = superficies neutras, contornos = ink)
.pxtt__cab { fill: var(--pxn-primary); stroke: var(--pxn-ink); }
.pxtt__window { fill: var(--pxn-ink-3); stroke: var(--pxn-ink); }
.pxtt__cargo { fill: var(--pxn-surface-2); stroke: var(--pxn-ink); }
.pxtt__light { fill: var(--pxn-warning-soft); stroke: var(--pxn-ink); }
.pxtt__ink { fill: var(--pxn-ink); stroke: var(--pxn-ink); }
.pxtt__tire-out { fill: var(--pxn-ink); stroke: var(--pxn-ink); }
.pxtt__hub { fill: var(--pxn-surface-2); }

// carretera
.pxtt__road { position: relative; bottom: 0; align-self: flex-end; width: 100%; height: 1.5px; border-radius: 3px; background: var(--pxn-ink); }
.pxtt__road::before, .pxtt__road::after {
  content: ""; position: absolute; height: 100%; border-radius: 3px; background: var(--pxn-ink);
  animation: pxtt-road 1.4s linear infinite;
}
.pxtt__road::before { width: calc(20px * var(--k)); right: -50%; border-left: 10px solid var(--pxn-surface); }
.pxtt__road::after { width: calc(10px * var(--k)); right: -65%; border-left: 4px solid var(--pxn-surface); }

// poste
.pxtt__lamp { position: absolute; bottom: 0; right: -90%; height: calc(90px * var(--k)); fill: var(--pxn-ink-2); animation: pxtt-road 1.4s linear infinite; }

@keyframes pxtt-motion { 0% { transform: translateY(0); } 50% { transform: translateY(calc(3px * var(--k))); } 100% { transform: translateY(0); } }
@keyframes pxtt-road { 0% { transform: translateX(0); } 100% { transform: translateX(calc(-350px * var(--k))); } }
@keyframes pxtt-in { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: translateX(0); } }

// Sin movimiento: camión estático (el texto "En tránsito" del paso sigue presente).
@media (prefers-reduced-motion: reduce) {
  .pxtt, .pxtt__body, .pxtt__road::before, .pxtt__road::after, .pxtt__lamp { animation: none; }
  .pxtt__road::before, .pxtt__road::after, .pxtt__lamp { display: none; }
}
</style>
