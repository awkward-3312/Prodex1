<template>
    <div>
       <div class="breadcrumb">
            <slot name="header">
               <h1>{{page}}</h1>
                    <ul>
                        <li v-if="folder"><router-link v-if="to" :to="to">{{ folder }}</router-link><span v-else>{{ folder }}</span></li>
                        <li> {{page}} </li>
                    </ul>
            </slot>
        </div>
        <div class="separator-breadcrumb border-top"></div>
    </div>
</template>
<script>
export default {
    props:['page','folder','to'],

}
</script>
