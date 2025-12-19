import './bootstrap';
import '../css/app.css';
//import 'primevue/resources/themes/lara-light-teal/theme.css';
import "primeicons/primeicons.css";
import wind from "./Presets/wind";
//import lara from "./Presets/lara";



import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ConfirmationService from 'primevue/confirmationservice';
import PrimeVue from "primevue/config";
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import ColumnGroup from 'primevue/columngroup';
import Row from 'primevue/row';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Textarea from 'primevue/textarea';
import Dropdown from 'primevue/dropdown';
import Toast from 'primevue/toast';
import ToastService from 'primevue/toastservice';
import Tooltip from 'primevue/tooltip';
import Dialog from 'primevue/dialog';
import Card from 'primevue/card';
import Calendar from 'primevue/calendar';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, Ziggy)
            .use(PrimeVue, {unstyled: true, pt: wind})
            .use(ToastService)
            .use(ConfirmationService)
            .directive('tooltip', Tooltip)
            .component('InputText', InputText)
            .component('Textarea', Textarea)
            .component('Dropdown', Dropdown)
            .component('Toast', Toast)
            .component('DataTable', DataTable)
            .component('Column', Column)
            .component('Button', Button)
            .component('IconField', IconField)
            .component('InputIcon', InputIcon)
            .component('ColumnGroup', ColumnGroup)
            .component('Row', Row)
            .component('Dialog', Dialog)
            .component('Card', Card)
            .component('Calendar', Calendar)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
