import { createApp } from 'vue'
import 'virtual:uno.css'
import App from './App.vue'
import { vuetify0 } from './plugins/vuetify0'
import './styles/base.css'

const app = createApp(App)
vuetify0(app)
app.mount('#app')
