import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import './assets/styles/global.scss'
import './assets/styles/app.scss'

const app = createApp(App)

app.use(createPinia())
app.mount('#app')
