<template>
  <div class="main-content">
    <breadcumb page="Edit Template" folder="WhatsApp" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-row v-if="!isLoading">
      <b-col md="7">
        <b-card title="Template Details">
          <b-form-group label="Name">
            <b-form-input v-model="form.name" placeholder="e.g. Invoice Created" />
          </b-form-group>
          <b-form-group label="Key" description="Used to match automation triggers.">
            <b-form-input v-model="form.key" placeholder="invoice_created" />
          </b-form-group>
          <b-row>
            <b-col md="6">
              <b-form-group label="Language">
                <b-form-input v-model="form.language" placeholder="en" />
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Category">
                <b-form-input v-model="form.category" placeholder="Optional" />
              </b-form-group>
            </b-col>
          </b-row>
          <b-form-group label="Message Body">
            <b-form-textarea v-model="form.body" rows="6" :placeholder="bodyPlaceholder" />
          </b-form-group>
          <b-form-checkbox v-model="form.is_active" switch>Active</b-form-checkbox>

          <div class="mt-3">
            <b-button variant="primary" @click="save" :disabled="saving"><lucide-icon name="check" /> Update</b-button>
            <router-link to="/app/whatsapp/templates" class="btn btn-outline-secondary ml-2">Cancel</router-link>
          </div>
        </b-card>
      </b-col>

      <b-col md="5">
        <b-card title="Variables">
          <p class="text-muted"><small>Click to insert into the message body.</small></p>
          <b-button
            v-for="(desc, v) in variables"
            :key="v"
            size="sm"
            variant="outline-info"
            class="m-1"
            :title="desc"
            @click="insertVar(v)"
          >{{ v }}</b-button>
        </b-card>

        <b-card title="Preview" class="mt-3">
          <div class="wa-preview">{{ preview }}</div>
        </b-card>
      </b-col>
    </b-row>
  </div>
</template>

<script>
export default {
  data() {
    return {
      isLoading: true,
      saving: false,
      variables: {},
      bodyPlaceholder: "Hello {{customer_name}}, ...",
      sample: {
        "{{customer_name}}": "John Doe",
        "{{invoice_number}}": "INV-1024",
        "{{total_amount}}": "149.00",
        "{{company_name}}": "Acme Store",
        "{{payment_status}}": "paid",
        "{{order_status}}": "confirmed",
        "{{date}}": "2026-06-03"
      },
      form: { name: "", key: "", body: "", language: "en", category: "", is_active: true }
    };
  },
  computed: {
    preview() {
      let out = this.form.body || "";
      Object.keys(this.sample).forEach(k => {
        out = out.split(k).join(this.sample[k]);
      });
      return out || "Your message preview will appear here.";
    }
  },
  mounted() {
    this.load();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    load() {
      const id = this.$route.params.id;
      Promise.all([
        axios.get("/whatsapp/templates/" + id),
        axios.get("/whatsapp/templates", { params: { per_page: 1 } })
      ])
        .then(([tplRes, listRes]) => {
          const t = tplRes.data;
          this.form = {
            name: t.name,
            key: t.key,
            body: t.body,
            language: t.language || "en",
            category: t.category || "",
            is_active: !!t.is_active
          };
          this.variables = listRes.data.variables || this.sample;
        })
        .catch(() => this.makeToast("danger", "Failed to load template.", "Error"))
        .finally(() => (this.isLoading = false));
    },
    insertVar(v) {
      this.form.body = (this.form.body || "") + v;
    },
    save() {
      this.saving = true;
      axios
        .put("/whatsapp/templates/" + this.$route.params.id, this.form)
        .then(() => {
          this.makeToast("success", "Template updated.", "Success");
          this.$router.push("/app/whatsapp/templates");
        })
        .catch(err => {
          const msg = (err.response && err.response.data && err.response.data.message) || "Failed to update.";
          this.makeToast("danger", msg, "Error");
        })
        .finally(() => (this.saving = false));
    }
  }
};
</script>

<style scoped>
.wa-preview {
  white-space: pre-wrap;
  background: #e5ffd9;
  border-radius: 8px;
  padding: 12px 14px;
  min-height: 80px;
  font-size: 0.9rem;
}
</style>
