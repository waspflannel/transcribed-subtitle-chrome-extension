import { defineConfig } from 'wxt';

export default defineConfig({
  manifest: {
    name: 'AI Subtitle Learning Overlay',
    description: 'Generated subtitle track and Arabic learning overlay for public YouTube videos.',
    permissions: ['storage'],
    host_permissions: ['http://localhost:8000/*'],
  },
});
