<template>
  <div class="px-next pxrd">
    <!--
      Migracion px-next — Panel de reclutamiento (Recruit Dashboard). Ruta
      real sin cambios (/app/recruit/dashboard). Esta vista NO es un CRUD:
      no se usa PxTable/PxModal, se preservó su naturaleza de dashboard de
      solo lectura. Auditado a fondo contra RecruitController@dashboard
      antes de tocar nada — un único GET, cero parámetros, cero filtros de
      fecha, cero paginación.

      Origen exacto de cada dato (sin cambios en cálculos/queries):
      - stats.total_jobs / open_jobs: COUNT sobre recruit_jobs (total y
        status='open').
      - stats.total_candidates: COUNT sobre recruit_candidates.
      - stats.total_applications: COUNT sobre recruit_applications.
      - stats.pending_interviews: COUNT recruit_interviews WHERE
        status='scheduled'.
      - stats.hired_count: COUNT recruit_applications WHERE stage='hired'.
      - recent_applications: últimas 10 por created_at DESC, con relación
        candidate+job — SIN paginación (no se agregó ninguna).
      - upcoming_interviews: SOLO status='scheduled' AND scheduled_at >=
        now(), orden ASC por scheduled_at, límite 10, con relación
        application.candidate/application.job.
      - pipeline: GROUP BY stage sobre recruit_applications completo (no
        filtra fecha) → {stage: count}. Etapas ausentes en el resultado se
        tratan como 0 en el cliente (`pipeline[s] || 0`), igual que legacy.

      Pipeline — comportamiento exacto preservado:
      - Orden de etapas FIJO: applied, screening, shortlisted, interview,
        offered, hired, rejected (array `stages`, sin reordenar).
      - Ancho de cada segmento = flex-grow (pipeline[stage]||0) + 0.02 — la
        constante +0.02 es lo que hace visible un segmento en 0 (nunca
        desaparece del todo). Se preserva la fórmula exacta.
      - Con 0 postulaciones en total: los 7 segmentos igual se dibujan,
        todos del mismo ancho mínimo — no hay estado "vacío" especial para
        el pipeline en el legacy, y no se inventó uno aquí.
      - Los segmentos NO son clicables — solo informativos, con tooltip
        (title nativo) mostrando "Etapa: cantidad". Se preserva sin
        agregar navegación nueva.
      - Colores: el legacy usaba 7 colores hexadecimales fijos por
        posición (índice 0–6), sin relación con los tokens de diseño. Para
        no introducir "colores arbitrarios nuevos" (regla del sistema
        px-next) pero conservando distinción visual por posición, se
        reasignó cada índice a uno de los 6 tonos auxiliares ya existentes
        de PxTag (`--pxn-tag-{hue}`) de forma determinista y CÍCLICA por
        índice — mismo orden, mismo mapeo posición→color, solo la paleta
        pasa a ser la del sistema en vez de hexadecimales sueltos.

      Tarjetas de estadísticas: 6 tarjetas fijas (Open_Jobs, Candidates,
      Applications, Pending_Interviews, Hired, Total_Jobs) — mismo array
      `statCards`, mismos labels/valores/iconos que el legacy. NO se
      agregó ningún delta/porcentaje de crecimiento (el backend no lo
      entrega, y las instrucciones prohíben inventarlo) — PxStat se usa
      sin la prop `delta`.

      Listas: mismos 10 registros máx., mismo orden, mismas relaciones.
      Sin paginación (no existía). Estado vacío ("No_data") preservado
      para AMBAS listas usando PxEmptyState — el pipeline no tiene estado
      vacío propio porque el legacy tampoco lo tenía.

      Permisos: authorizeForUser('view', RecruitJob::class) — el dashboard
      NO tiene un permiso dedicado, usa el de Vacantes. No se inventó un
      permiso "recruit_dashboard" nuevo.
    -->
    <px-page-header :title="$t('Recruit')" :subtitle="$t('Dashboard')" :breadcrumbs="[{ label: $t('Recruit') }, { label: $t('Dashboard') }]" />

    <div v-if="isLoading" class="pxrd__skeletons">
      <div class="pxrd__statgrid">
        <px-skeleton v-for="n in 6" :key="n" variant="card" height="88px" />
      </div>
      <px-skeleton variant="card" height="140px" />
      <div class="pxrd__grid">
        <px-skeleton variant="card" height="260px" />
        <px-skeleton variant="card" height="260px" />
      </div>
    </div>

    <template v-else>
      <!-- Tarjetas de estadísticas -->
      <div class="pxrd__statgrid">
        <div v-for="card in statCards" :key="card.label" class="pxrd__statcard">
          <div class="pxrd__staticon" :style="{ color: card.accent }">
            <lucide-icon :name="card.icon" :size="20" />
          </div>
          <px-stat :label="$t(card.label)" :value="card.value" />
        </div>
      </div>

      <!-- Pipeline -->
      <px-card :title="$t('Pipeline')" class="pxrd__field">
        <div class="pxrd__pipelinebar">
          <div
            v-for="(s, idx) in stages"
            :key="'seg-' + s"
            class="pxrd__seg"
            :style="{ flex: (pipeline[s] || 0) + 0.02, background: hueFor(idx) }"
            :title="formatLabel(s) + ': ' + (pipeline[s] || 0)"
          ></div>
        </div>
        <div class="pxrd__legend">
          <div v-for="(s, idx) in stages" :key="'leg-' + s" class="pxrd__legenditem">
            <span class="pxrd__legenddot" :style="{ background: hueFor(idx) }"></span>
            <span class="pxrd__legendname">{{ formatLabel(s) }}</span>
            <span class="pxrd__legendcount">{{ pipeline[s] || 0 }}</span>
          </div>
        </div>
      </px-card>

      <div class="pxrd__grid">
        <!-- Postulaciones recientes -->
        <px-card :title="$t('Recent_Applications')" flush>
          <px-empty-state
            v-if="recent_applications.length === 0"
            icon="inbox"
            :title="$t('Recent_Applications')"
            :description="$t('No_data')"
          />
          <ul v-else class="pxrd__list">
            <li v-for="a in recent_applications" :key="a.id" class="pxrd__listitem">
              <px-avatar :name="candidateNameApp(a)" size="md" />
              <div class="pxrd__listmain">
                <div class="pxrd__listname">{{ candidateNameApp(a) }}</div>
                <div class="pxrd__listsub">{{ a.job ? a.job.title : '-' }}</div>
              </div>
              <div class="pxrd__listmeta">
                <px-badge :tone="stageTone(a.stage)">{{ formatLabel(a.stage) }}</px-badge>
                <span class="pxrd__date">{{ a.applied_date }}</span>
              </div>
            </li>
          </ul>
        </px-card>

        <!-- Próximas entrevistas -->
        <px-card :title="$t('Upcoming_Interviews')" flush>
          <px-empty-state
            v-if="upcoming_interviews.length === 0"
            icon="calendar"
            :title="$t('Upcoming_Interviews')"
            :description="$t('No_data')"
          />
          <ul v-else class="pxrd__list">
            <li v-for="i in upcoming_interviews" :key="i.id" class="pxrd__listitem">
              <px-avatar icon="calendar" size="md" />
              <div class="pxrd__listmain">
                <div class="pxrd__listname">{{ interviewCandidate(i) }}</div>
                <div class="pxrd__listsub">{{ formatLabel(i.type) }}</div>
              </div>
              <div class="pxrd__listmeta">
                <px-badge tone="info">{{ formatLabel(i.status) }}</px-badge>
                <span class="pxrd__date">{{ formatDatetime(i.scheduled_at) }}</span>
              </div>
            </li>
          </ul>
        </px-card>
      </div>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAvatar from "@/components/px-next/PxAvatar.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";

const HUES = ["slate", "indigo", "teal", "plum", "clay", "moss"];

export default {
  name: "RecruitDashboardNext",
  metaInfo: { title: "Recruit Dashboard" },
  components: { PxPageHeader, PxCard, PxStat, PxBadge, PxAvatar, PxEmptyState, PxSkeleton },
  data() {
    return {
      isLoading: true,
      stats: {},
      recent_applications: [],
      upcoming_interviews: [],
      pipeline: {},
      stages: ["applied", "screening", "shortlisted", "interview", "offered", "hired", "rejected"]
    };
  },
  computed: {
    statCards() {
      return [
        { label: "Open_Jobs", value: this.stats.open_jobs || 0, icon: "briefcase-business", accent: "var(--pxn-primary)" },
        { label: "Candidates", value: this.stats.total_candidates || 0, icon: "users", accent: "var(--pxn-info)" },
        { label: "Applications", value: this.stats.total_applications || 0, icon: "file-text", accent: "var(--pxn-tag-plum)" },
        { label: "Pending_Interviews", value: this.stats.pending_interviews || 0, icon: "calendar", accent: "var(--pxn-warning)" },
        { label: "Hired", value: this.stats.hired_count || 0, icon: "check-circle", accent: "var(--pxn-success)" },
        { label: "Total_Jobs", value: this.stats.total_jobs || 0, icon: "clipboard-list", accent: "var(--pxn-ink-2)" }
      ];
    }
  },
  methods: {
    formatLabel(v) { return v ? String(v).replace(/_/g, " ") : "-"; },
    formatDatetime(v) {
      if (!v) return "-";
      return String(v).replace("T", " ").substring(0, 16);
    },
    candidateNameApp(a) {
      return a.candidate ? a.candidate.first_name + " " + a.candidate.last_name : "-";
    },
    interviewCandidate(i) {
      const c = i.application && i.application.candidate;
      return c ? c.first_name + " " + c.last_name : "-";
    },
    hueFor(idx) { return `var(--pxn-tag-${HUES[idx % HUES.length]})`; },
    stageTone(s) {
      const map = { hired: "success", rejected: "danger", interview: "warning", offered: "warning", screening: "info", shortlisted: "info" };
      return map[s] || "neutral";
    },
    Get_Dashboard() {
      axios
        .get("recruit/dashboard")
        .then(({ data }) => {
          this.stats = data.stats;
          this.recent_applications = data.recent_applications;
          this.upcoming_interviews = data.upcoming_interviews;
          this.pipeline = data.pipeline;
          this.isLoading = false;
        })
        .catch(() => {
          this.isLoading = false;
        });
    }
  },
  created() {
    this.Get_Dashboard();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrd { padding: var(--pxn-space-6) var(--pxn-space-5); } }

.pxrd__skeletons { display: flex; flex-direction: column; gap: var(--pxn-space-6); margin-top: var(--pxn-space-6); }
.pxrd__field { margin-top: var(--pxn-space-6); }

.pxrd__statgrid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-6);
}
.pxrd__statcard {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-6);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
}
.pxrd__staticon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2);
  flex: none;
}

.pxrd__pipelinebar { display: flex; gap: var(--pxn-space-3); height: 14px; }
.pxrd__seg { border-radius: var(--pxn-radius-xs); min-width: 6px; transition: flex var(--pxn-dur-2) var(--pxn-ease); }
.pxrd__legend { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4) var(--pxn-space-6); margin-top: var(--pxn-space-5); }
.pxrd__legenditem { display: inline-flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); }
.pxrd__legenddot { width: 9px; height: 9px; border-radius: 999px; flex: none; }
.pxrd__legendname { color: var(--pxn-ink-2); text-transform: capitalize; }
.pxrd__legendcount { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }

.pxrd__grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-6); margin-top: var(--pxn-space-6); }
@media (max-width: 900px) { .pxrd__grid { grid-template-columns: 1fr; } }

.pxrd__list { list-style: none; margin: 0; padding: 0; }
.pxrd__listitem {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-5) var(--pxn-space-7);
  border-bottom: 1px solid var(--pxn-border);
}
.pxrd__listitem:last-child { border-bottom: none; }
.pxrd__listmain { flex: 1; min-width: 0; }
.pxrd__listname { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pxrd__listsub { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: capitalize; margin-top: 2px; }
.pxrd__listmeta { text-align: right; flex: none; }
.pxrd__date { display: block; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-top: var(--pxn-space-3); }
</style>
