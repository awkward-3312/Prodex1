<template><role-editor mode="edit" :role-id="$route.params.id"/></template>
<script>
import RoleEditor from './RoleEditor.vue';
export default { components: { RoleEditor }, metaInfo: { title: 'Editar rol' } };
</script>
