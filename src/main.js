import Vue from 'vue'
import { getRequestToken } from '@nextcloud/auth'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'

Vue.mixin({ methods: { t, n } })

console.log('FolderCast: main.js loaded');
const View = Vue.extend(App)
document.addEventListener('DOMContentLoaded', () => {
    console.log('FolderCast: DOMContentLoaded');
    if (document.getElementById('foldercast')) {
        console.log('FolderCast: Mounting Vue...');
        new View().$mount('#foldercast')
    } else {
        console.error('FolderCast: #foldercast element not found!');
    }
})
