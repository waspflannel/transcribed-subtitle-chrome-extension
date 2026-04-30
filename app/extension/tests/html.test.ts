import { describe, expect, it } from 'vitest';

import { escapeHtml } from '../utils/html';

describe('escapeHtml', () => {
  it('escapes text before it is inserted into HTML strings', () => {
    expect(escapeHtml(`Tom & "Jerry" <script>alert('x')</script>`)).toBe(
      'Tom &amp; &quot;Jerry&quot; &lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;',
    );
  });
});
