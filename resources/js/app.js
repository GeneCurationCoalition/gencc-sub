import './bootstrap';
import '../css/app.css';
//import 'primevue/resources/themes/lara-light-teal/theme.css';
import "primeicons/primeicons.css";
import wind from "./Presets/wind";
//import lara from "./Presets/lara";



import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

// Handle session expiration and CSRF token mismatch errors
// When a 419 (CSRF) or 401 (Unauthenticated) error occurs, refresh the page
// to get a new session/token instead of showing a confusing error
router.on('invalid', (event) => {
    const status = event.detail.response.status;

    // 419 = CSRF token mismatch (session expired)
    // 401 = Unauthenticated
    if (status === 419 || status === 401) {
        event.preventDefault();
        // Reload the page to get fresh CSRF token and redirect to login
        window.location.reload();
    }
});
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
import FileUpload from 'primevue/fileupload';
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
            .component('FileUpload', FileUpload)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
