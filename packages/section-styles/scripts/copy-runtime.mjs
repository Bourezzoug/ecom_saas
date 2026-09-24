// Copies the JS runtime next to the compiled CSS: dist/aisg.js (our components) and
// dist/alpine.min.js (pinned Alpine build). Load order on a page: aisg.js, then alpine.min.js.
import { copyFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const dist = new URL('../dist/', import.meta.url);

mkdirSync(dist, { recursive: true });
copyFileSync(new URL('../src/aisg.js', import.meta.url), new URL('aisg.js', dist));
copyFileSync(require.resolve('alpinejs/dist/cdn.min.js'), new URL('alpine.min.js', dist));

console.log('runtime copied: dist/aisg.js, dist/alpine.min.js');
