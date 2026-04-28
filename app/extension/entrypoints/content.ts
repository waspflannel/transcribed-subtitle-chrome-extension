export default defineContentScript({
  matches: ['*://*.youtube.com/watch*'],
  main() {
    // Phase 02 owns YouTube page detection and overlay mounting.
  },
});
