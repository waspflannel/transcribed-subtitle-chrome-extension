import { defineConfig } from 'wxt';

export default defineConfig({
  manifest: {
    name: 'AI Subtitle Learning Overlay',
    description: 'Generated subtitle track and language-learning overlay for public YouTube videos.',
    permissions: ['activeTab', 'storage'],
    host_permissions: ['*://*.youtube.com/*', 'http://localhost:8000/*'],
  },
});
