import { defineConfig } from 'wxt';

export default defineConfig({
  manifest: {
    name: 'AI Language Subtitles',
    description: 'Generated subtitles and language-to-language word cards for public YouTube videos.',
    permissions: ['activeTab', 'storage'],
    host_permissions: ['*://*.youtube.com/*', 'http://localhost:8000/*'],
  },
});
