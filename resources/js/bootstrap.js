/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF-TOKEN" cookie.
 *
 * The withXSRFToken option (axios 1.6+) automatically reads the XSRF-TOKEN
 * cookie and sends it as the X-XSRF-TOKEN header. This is more reliable than
 * the meta tag approach because:
 * 1. The cookie is automatically updated by Laravel on every response
 * 2. No need to manually sync after SPA navigation or session regeneration
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

/**
 * Handle CSRF token mismatch (419) by refreshing the page.
 * This commonly occurs after server restarts when the browser has a stale token,
 * or when the session has expired.
 */
window.axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 419) {
            // CSRF token mismatch - refresh the page to get a new token
            console.warn('CSRF token expired, refreshing page...');
            window.location.reload();
            // Return a pending promise to prevent error handlers from firing
            return new Promise(() => {});
        }
        return Promise.reject(error);
    }
);
