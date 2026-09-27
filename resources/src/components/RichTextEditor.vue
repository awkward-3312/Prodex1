<template>
  <div class="quill-editor-wrapper">
    <div ref="editor" :id="editorId"></div>
  </div>
</template>

<script>
import Quill from 'quill';
import 'quill/dist/quill.snow.css';

export default {
  name: 'RichTextEditor',
  emits: ['update:modelValue', 'input'],
  props: {
    modelValue: {
      type: String,
      default: undefined
    },
    value: {
      type: String,
      default: undefined
    },
    editorId: {
      type: String,
      default: 'editor'
    },
    editorToolbar: {
      type: Array,
      default: () => [
        [{ header: [1, 2, 3, 4, 5, 6, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ color: [] }, { background: [] }],
        [{ align: [] }],
        ['link', 'image'],
        ['clean']
      ]
    }
  },
  data() {
    return {
      quill: null,
      isUpdating: false
    };
  },
  mounted() {
    this.initQuill();
  },
  beforeUnmount() {
    if (this.quill) {
      this.quill = null;
    }
  },
  computed: {
    // Vue 3 real: `v-model` propio compila a `modelValue`/`update:modelValue`.
    // `value`/`input` se conservan como API explícita para quien los use así directamente.
    internalValue() {
      return this.modelValue !== undefined ? this.modelValue : this.value;
    }
  },
  watch: {
    internalValue(newVal) {
      if (this.quill && !this.isUpdating) {
        const currentContent = this.quill.root.innerHTML;
        if (currentContent !== newVal) {
          this.quill.root.innerHTML = newVal;
        }
      }
    }
  },
  methods: {
    initQuill() {
      this.quill = new Quill(this.$refs.editor, {
        theme: 'snow',
        modules: {
          toolbar: this.editorToolbar
        }
      });

      // Set initial content
      if (this.internalValue) {
        this.quill.root.innerHTML = this.internalValue;
      }

      // Listen for text changes
      this.quill.on('text-change', () => {
        this.isUpdating = true;
        const html = this.quill.root.innerHTML;
        this.$emit('update:modelValue', html);
        this.$emit('input', html);
        this.$nextTick(() => {
          this.isUpdating = false;
        });
      });
    }
  }
};
</script>

<style>
.quill-editor-wrapper {
  background: white;
}
.ql-container {
  min-height: 200px;
}
.ql-editor {
  min-height: 200px;
}
</style>

