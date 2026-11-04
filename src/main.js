import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(App)

const mount = () => {
    const el = document.getElementById('foldercast')
    if (el) {
        new View().$mount(el)
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount)
} else {
    mount()
}
