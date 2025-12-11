import Vue from 'vue'
import { getRequestToken } from '@nextcloud/auth'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(App)
new View().$mount('#foldercast')
