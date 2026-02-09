/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

// Explicitly set CSRF token from meta tag (Laravel's default approach)
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

/**
 * Handle CSRF token mismatch (419) by refreshing the page.
 * This commonly occurs after server restarts when the browser has a stale token.
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
