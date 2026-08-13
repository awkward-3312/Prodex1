<template>
  <div class="main-content">
    <breadcumb :page="editMode ? $t('Edit_Property') : $t('Add_Property')" :folder="$t('Real_Estate')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-form @submit.prevent="submit" v-if="!isLoading">
      <b-row>
        <!-- LEFT COLUMN -->
        <b-col lg="8">
          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Property_Information') }}</h5>
            <b-row>
              <b-col md="12">
                <b-form-group :label="$t('Property_Title') + ' *'">
                  <b-form-input v-model="form.title" :state="submitted ? !!form.title : null" required />
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('Property_Type')">
                  <v-select v-model="form.property_category_id" :reduce="o => o.value"
                    :options="categories.map(c => ({ label: c.name, value: c.id }))"
                    :placeholder="$t('Select')" />
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Purpose')">
                  <b-form-select v-model="form.purpose">
                    <b-form-select-option value="sale">{{ $t('For_Sale') }}</b-form-select-option>
                    <b-form-select-option value="rent">{{ $t('For_Rent') }}</b-form-select-option>
                  </b-form-select>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Status')">
                  <b-form-select v-model="form.status">
                    <b-form-select-option value="available">{{ $t('Available') }}</b-form-select-option>
                    <b-form-select-option value="sold">{{ $t('Sold') }}</b-form-select-option>
                    <b-form-select-option value="rented">{{ $t('Rented') }}</b-form-select-option>
                  </b-form-select>
                </b-form-group>
              </b-col>
              <b-col md="12">
                <b-form-group :label="$t('Description')">
                  <b-form-textarea v-model="form.description" rows="5" />
                </b-form-group>
              </b-col>
            </b-row>
          </b-card>

          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Specifications') }}</h5>
            <b-row>
              <b-col md="4">
                <b-form-group :label="$t('Price')">
                  <b-form-input type="number" step="0.01" min="0" v-model="form.price" />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group :label="$t('Area')">
                  <b-form-input type="number" step="0.01" min="0" v-model="form.area" />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group :label="$t('Area_Unit')">
                  <b-form-input v-model="form.area_unit" placeholder="m²" />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group :label="$t('Bedrooms')">
                  <b-form-input type="number" min="0" v-model="form.bedrooms" />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group :label="$t('Bathrooms')">
                  <b-form-input type="number" min="0" v-model="form.bathrooms" />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group :label="$t('Garage')">
                  <b-form-input type="number" min="0" v-model="form.garage" />
                </b-form-group>
              </b-col>
            </b-row>
          </b-card>

          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Location') }}</h5>
            <b-row>
              <b-col md="12">
                <b-form-group :label="$t('Address')">
                  <b-form-input v-model="form.address" />
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('City')">
                  <b-form-input v-model="form.city" />
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('Region')">
                  <b-form-input v-model="form.region" />
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('Latitude')">
                  <b-form-input v-model="form.latitude" />
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('Longitude')">
                  <b-form-input v-model="form.longitude" />
                </b-form-group>
              </b-col>
            </b-row>
          </b-card>

          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Features_Amenities') }}</h5>
            <div class="d-flex flex-wrap mb-2">
              <span v-for="(a, i) in form.amenities" :key="i" class="badge badge-primary p-2 m-1 d-inline-flex align-items-center">
                {{ a }}
                <a class="text-white ml-2 cursor-pointer" @click="removeAmenity(i)">&times;</a>
              </span>
            </div>
            <b-input-group>
              <b-form-input v-model="amenityInput" :placeholder="$t('Add_Amenity')" @keydown.enter.prevent="addAmenity" />
              <b-input-group-append>
                <b-button variant="primary" @click="addAmenity">{{ $t('Add') }}</b-button>
              </b-input-group-append>
            </b-input-group>
          </b-card>

          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('SEO') }}</h5>
            <b-form-group :label="$t('SEO_Title')">
              <b-form-input v-model="form.seo_title" />
            </b-form-group>
            <b-form-group :label="$t('SEO_Description')">
              <b-form-textarea v-model="form.seo_description" rows="2" />
            </b-form-group>
            <b-form-group :label="$t('SEO_Keywords')">
              <b-form-input v-model="form.seo_keywords" :placeholder="$t('Comma_separated')" />
            </b-form-group>
          </b-card>
        </b-col>

        <!-- RIGHT COLUMN -->
        <b-col lg="4">
          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Featured_Image') }}</h5>
            <div v-if="featuredPreview" class="mb-2">
              <img :src="featuredPreview" class="img-fluid rounded" style="max-height:180px" />
            </div>
            <b-form-file accept="image/*" @change="onFeaturedChange" :placeholder="$t('Choose_file')" />
          </b-card>

          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Gallery') }}</h5>
            <div class="d-flex flex-wrap mb-2">
              <div v-for="(g, i) in existingGallery" :key="'e'+i" class="position-relative m-1">
                <img :src="'/' + g" class="rounded" style="width:80px;height:62px;object-fit:cover" />
                <a class="badge badge-danger position-absolute cursor-pointer" style="top:-6px;right:-6px" @click="removeExisting(i)">&times;</a>
              </div>
              <div v-for="(g, i) in galleryPreviews" :key="'n'+i" class="position-relative m-1">
                <img :src="g" class="rounded" style="width:80px;height:62px;object-fit:cover" />
                <a class="badge badge-danger position-absolute cursor-pointer" style="top:-6px;right:-6px" @click="removeNew(i)">&times;</a>
              </div>
            </div>
            <b-form-file accept="image/*" multiple @change="onGalleryChange" :placeholder="$t('Choose_files')" />
          </b-card>

          <b-card class="wrapper mb-3">
            <b-form-checkbox v-model="form.featured" switch>{{ $t('Mark_as_Featured') }}</b-form-checkbox>
          </b-card>

          <b-card class="wrapper mb-3">
            <h5 class="mb-3">{{ $t('Agent_Information') }}</h5>
            <b-form-group :label="$t('Agent_Name')">
              <b-form-input v-model="form.agent_name" />
            </b-form-group>
            <b-form-group :label="$t('Phone')">
              <b-form-input v-model="form.agent_phone" />
            </b-form-group>
            <b-form-group :label="$t('Email')">
              <b-form-input v-model="form.agent_email" />
            </b-form-group>
            <b-form-group :label="$t('WhatsApp')">
              <b-form-input v-model="form.agent_whatsapp" />
            </b-form-group>
          </b-card>

          <b-card class="wrapper mb-3">
            <b-button type="submit" variant="primary" block :disabled="saving">
              <span v-if="saving" class="spinner-border spinner-border-sm mr-1"></span>
              {{ editMode ? $t('Update') : $t('Save') }}
            </b-button>
            <b-button variant="outline-secondary" block @click="$router.push({ name: 'realestate_properties' })">{{ $t('Cancel') }}</b-button>
          </b-card>
        </b-col>
      </b-row>
    </b-form>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Property Form" },
  data() {
    return {
      isLoading: true,
      saving: false,
      submitted: false,
      editMode: false,
      id: null,
      categories: [],
      amenityInput: "",
      featuredFile: null,
      featuredPreview: null,
      newGalleryFiles: [],
      galleryPreviews: [],
      existingGallery: [],
      form: {
        title: "", property_category_id: null, description: "",
        purpose: "sale", status: "available", featured: false,
        price: "", area: "", area_unit: "m²", bedrooms: "", bathrooms: "", garage: "",
        address: "", city: "", region: "", latitude: "", longitude: "",
        amenities: [],
        agent_name: "", agent_phone: "", agent_email: "", agent_whatsapp: "",
        seo_title: "", seo_description: "", seo_keywords: ""
      }
    };
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    addAmenity() {
      const v = (this.amenityInput || "").trim();
      if (v && !this.form.amenities.includes(v)) this.form.amenities.push(v);
      this.amenityInput = "";
    },
    removeAmenity(i) { this.form.amenities.splice(i, 1); },
    onFeaturedChange(e) {
      const f = e.target.files[0];
      if (!f) return;
      this.featuredFile = f;
      this.featuredPreview = URL.createObjectURL(f);
    },
    onGalleryChange(e) {
      const files = Array.from(e.target.files || []);
      files.forEach(f => {
        this.newGalleryFiles.push(f);
        this.galleryPreviews.push(URL.createObjectURL(f));
      });
    },
    removeExisting(i) { this.existingGallery.splice(i, 1); },
    removeNew(i) { this.newGalleryFiles.splice(i, 1); this.galleryPreviews.splice(i, 1); },

    Get_Categories() {
      return axios.get("realestate/categories_all").then(res => { this.categories = res.data.categories; }).catch(() => {});
    },

    Get_Property() {
      return axios.get("realestate/properties/" + this.id).then(res => {
        const p = res.data.property;
        this.form.title = p.title;
        this.form.property_category_id = p.property_category_id;
        this.form.description = p.description || "";
        this.form.purpose = p.purpose || "sale";
        this.form.status = p.status || "available";
        this.form.featured = !!p.featured;
        this.form.price = p.price; this.form.area = p.area; this.form.area_unit = p.area_unit || "m²";
        this.form.bedrooms = p.bedrooms; this.form.bathrooms = p.bathrooms; this.form.garage = p.garage;
        this.form.address = p.address || ""; this.form.city = p.city || ""; this.form.region = p.region || "";
        this.form.latitude = p.latitude; this.form.longitude = p.longitude;
        this.form.amenities = Array.isArray(p.amenities) ? p.amenities : [];
        this.form.agent_name = p.agent_name || ""; this.form.agent_phone = p.agent_phone || "";
        this.form.agent_email = p.agent_email || ""; this.form.agent_whatsapp = p.agent_whatsapp || "";
        this.form.seo_title = p.seo_title || ""; this.form.seo_description = p.seo_description || ""; this.form.seo_keywords = p.seo_keywords || "";
        this.existingGallery = Array.isArray(p.gallery) ? p.gallery.slice() : [];
        if (p.featured_image) this.featuredPreview = "/" + p.featured_image;
      });
    },

    buildFormData() {
      const fd = new FormData();
      const f = this.form;
      const scalar = ["title", "property_category_id", "description", "purpose", "status",
        "price", "area", "area_unit", "bedrooms", "bathrooms", "garage",
        "address", "city", "region", "latitude", "longitude",
        "agent_name", "agent_phone", "agent_email", "agent_whatsapp",
        "seo_title", "seo_description", "seo_keywords"];
      scalar.forEach(k => {
        if (f[k] !== null && f[k] !== undefined && f[k] !== "") fd.append(k, f[k]);
      });
      fd.append("featured", f.featured ? 1 : 0);
      fd.append("amenities", JSON.stringify(f.amenities || []));
      fd.append("gallery_existing", JSON.stringify(this.existingGallery || []));
      if (this.featuredFile) fd.append("featured_image_file", this.featuredFile);
      this.newGalleryFiles.forEach(file => fd.append("gallery_files[]", file));
      return fd;
    },

    submit() {
      this.submitted = true;
      if (!this.form.title) {
        this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        return;
      }
      this.saving = true;
      const fd = this.buildFormData();
      const url = this.editMode ? "realestate/properties/" + this.id : "realestate/properties";
      axios.post(url, fd, { headers: { "Content-Type": "multipart/form-data" } })
        .then(() => {
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
          this.$router.push({ name: "realestate_properties" });
        })
        .catch(err => {
          this.saving = false;
          const msg = err.response && err.response.data && err.response.data.message ? err.response.data.message : this.$t("InvalidData");
          this.makeToast("danger", msg, this.$t("Failed"));
        });
    }
  },
  created() {
    this.id = this.$route.params.id || null;
    this.editMode = !!this.id;
    const tasks = [this.Get_Categories()];
    if (this.editMode) tasks.push(this.Get_Property());
    Promise.all(tasks).finally(() => { this.isLoading = false; });
  }
};
</script>
