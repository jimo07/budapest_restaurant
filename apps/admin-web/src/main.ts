import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import './assets/styles/global.scss'
import './assets/styles/editor.scss'

const app = createApp(App)

app.use(createPinia())

app.mount('#app')
