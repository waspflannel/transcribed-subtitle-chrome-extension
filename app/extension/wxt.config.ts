import { defineConfig } from 'wxt';
import { backendApiHostPermission, resolveBackendApiBaseUrl } from './utils/api-config';

export default defineConfig({
  manifest: ({ browser }) => {
    const backendApiBaseUrl = resolveBackendApiBaseUrl(import.meta.env.WXT_BACKEND_API_BASE_URL);

    return {
      name: '__MSG_extensionName__',
      description: '__MSG_extensionDescription__',
      default_locale: 'en',
      action: {},
      // `sidePanel` is Chromium-only; Firefox uses sidebar_action and warns on unknown permissions.
      permissions: ['activeTab', 'storage', ...(browser === 'firefox' ? [] : ['sidePanel'])],
      host_permissions: ['*://*.youtube.com/*', backendApiHostPermission(backendApiBaseUrl)],
      web_accessible_resources: [
        { resources: ['fonts/*'], matches: ['*://*.youtube.com/*'] },
      ],
    };
  },
});
