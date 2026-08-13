<template>
  <div class="main-content">
    <breadcumb :page="$t('create_ticket')" :folder="$t('support_center')" />

    <b-row>
      <b-col lg="8" md="10" sm="12" class="mx-auto">
        <b-card :title="$t('open_a_ticket')">
          <b-form @submit.prevent="submit">
            <b-row>
              <!-- Subject -->
              <b-col md="12">
                <b-form-group :label="$t('subject') + ' *'">
                  <b-form-input
                    v-model="form.subject"
                    :placeholder="$t('subject_placeholder')"
                    required
                  ></b-form-input>
                </b-form-group>
              </b-col>

              <!-- Category -->
              <b-col md="6">
                <b-form-group :label="$t('category') + ' *'">
                  <b-form-select v-model="form.category" required>
                    <b-form-select-option :value="null" disabled>{{ $t('choose_category') }}</b-form-select-option>
                    <b-form-select-option value="bug">{{ $t('cat_bug') }}</b-form-select-option>
                    <b-form-select-option value="technical">{{ $t('cat_technical') }}</b-form-select-option>
                    <b-form-select-option value="billing">{{ $t('cat_billing') }}</b-form-select-option>
                    <b-form-select-option value="feature_request">{{ $t('cat_feature_request') }}</b-form-select-option>
                    <b-form-select-option value="account">{{ $t('cat_account') }}</b-form-select-option>
                  </b-form-select>
                </b-form-group>
              </b-col>

              <!-- Priority -->
              <b-col md="6">
                <b-form-group :label="$t('priority') + ' *'">
                  <b-form-select v-model="form.priority" required>
                    <b-form-select-option :value="null" disabled>{{ $t('choose_priority') }}</b-form-select-option>
                    <b-form-select-option value="low">{{ $t('pri_low') }}</b-form-select-option>
                    <b-form-select-option value="medium">{{ $t('pri_medium') }}</b-form-select-option>
                    <b-form-select-option value="high">{{ $t('pri_high') }}</b-form-select-option>
                    <b-form-select-option value="urgent">{{ $t('pri_urgent') }}</b-form-select-option>
                  </b-form-select>
                </b-form-group>
              </b-col>

              <!-- Message -->
              <b-col md="12">
                <b-form-group :label="$t('message') + ' *'">
                  <b-form-textarea
                    v-model="form.message"
                    rows="6"
                    :placeholder="$t('message_placeholder')"
                    required
                  ></b-form-textarea>
                </b-form-group>
              </b-col>

              <!-- Attachments -->
              <b-col md="12">
                <b-form-group :label="$t('attach_files')">
                  <b-form-file
                    v-model="files"
                    multiple
                    :placeholder="$t('attach_files')"
                    accept=".jpg,.jpeg,.png,.pdf"
                  ></b-form-file>
                  <small class="text-muted">{{ $t('attachments_hint') }}</small>
                </b-form-group>
              </b-col>
            </b-row>

            <div class="mt-2">
              <b-button type="submit" variant="primary" :disabled="processing">
                <lucide-icon name="send" /> {{ $t('submit') }}
              </b-button>
              <router-link to="/app/support/tickets" class="btn btn-outline-secondary ml-2">
                {{ $t('cancel') }}
              </router-link>
            </div>
          </b-form>
        </b-card>
      </b-col>
    </b-row>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Create Support Ticket" },
  data() {
    return {
      processing: false,
      form: {
        subject: "",
        category: null,
        priority: "medium",
        message: ""
      },
      files: []
    };
  },
  methods: {
    submit() {
      if (!this.form.subject || !this.form.category || !this.form.priority || !this.form.message) {
        this.makeToast("warning", this.$t("please_fill_required"), this.$t("Warning"));
        return;
      }

      this.processing = true;
      NProgress.start();
      NProgress.set(0.1);

      const data = new FormData();
      data.append("subject", this.form.subject);
      data.append("category", this.form.category);
      data.append("priority", this.form.priority);
      data.append("message", this.form.message);
      if (this.files && this.files.length) {
        this.files.forEach(f => data.append("attachments[]", f));
      }

      axios
        .post("support/tickets", data, { headers: { "Content-Type": "multipart/form-data" } })
        .then(response => {
          NProgress.done();
          this.processing = false;
          this.makeToast("success", this.$t("ticket_created_success"), this.$t("Success"));
          const id = response.data.ticket && response.data.ticket.id;
          this.$router.push(id ? "/app/support/tickets/" + id : "/app/support/tickets");
        })
        .catch(error => {
          NProgress.done();
          this.processing = false;
          const msg =
            (error.response && error.response.data && error.response.data.message) ||
            this.$t("please_fill_required");
          this.makeToast("danger", msg, this.$t("Failed"));
        });
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    }
  }
};
</script>
