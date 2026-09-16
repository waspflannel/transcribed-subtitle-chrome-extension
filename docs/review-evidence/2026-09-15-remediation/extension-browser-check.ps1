# Start extension-fixture.cjs first. Uses an isolated browser with fake transport only.
$ErrorActionPreference = 'Stop'
function Invoke-FixtureBrowser([string[]] $BrowserArguments) {
    $response = (& agent-browser --session remediation-ui --json @BrowserArguments | ConvertFrom-Json)
    if ($LASTEXITCODE -ne 0 -or -not $response.success) { throw ($response | ConvertTo-Json -Depth 15) }
    return $response.data
}
function Invoke-FixtureScript([string] $Script) {
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Script))
    return (Invoke-FixtureBrowser @('eval', '-b', $encoded)).result
}
$geometryScript = @'
(() => {
  const rows = [...document.querySelectorAll('.cue')].slice(0, 2).map(row => {
    const line = row.querySelector('.toks');
    const tokens = [...line.querySelectorAll('.tok')].map(token => {
      const rect = token.getBoundingClientRect();
      return {text: token.querySelector('.tok-text').textContent, left: rect.left, right: rect.right, top: rect.top,
        readingDirection: getComputedStyle(token.querySelector('small')).direction};
    });
    if (getComputedStyle(line).direction !== 'rtl') throw Error('Source line is not RTL');
    tokens.forEach((token, i) => {
      if (token.readingDirection !== 'ltr') throw Error('Unreadable reading direction');
      if (i && token.top < tokens[i-1].top) throw Error('Unexpected reversed wrapping');
      if (i && Math.abs(token.top - tokens[i-1].top) < 1 && token.left >= tokens[i-1].left) throw Error('Tokens are visually reversed');
      if (token.left < 0 || token.right > innerWidth) throw Error('Horizontal overflow');
    });
    return {cue: row.dataset.cueId, direction: getComputedStyle(line).direction, tokens};
  });
  const latin = [...document.querySelectorAll('.tok-text')].find(token => token.textContent === 'YouTube!').firstChild;
  const x = i => { const range = document.createRange(); range.setStart(latin, i); range.setEnd(latin, i+1); return range.getBoundingClientRect().left; };
  if (x(0) >= x(7)) throw Error('Latin punctuation was reversed inside RTL source');
  if (document.documentElement.scrollWidth > innerWidth) throw Error('Page horizontal overflow');
  return {rows, latinPunctuation: {firstLetter: x(0), exclamation: x(7)}};
})()
'@
$results = @()
foreach ($language in @('ara', 'heb')) {
    foreach ($width in @(320, 360)) {
        Invoke-FixtureBrowser @('set', 'viewport', "$width", '800') | Out-Null
        Invoke-FixtureBrowser @('open', "http://127.0.0.1:8772/?lang=$language") | Out-Null
        Invoke-FixtureBrowser @('wait', '.toks') | Out-Null
        $normal = Invoke-FixtureScript $geometryScript
        Invoke-FixtureBrowser @('screenshot', (Join-Path $PSScriptRoot "$language-$width-normal.png")) | Out-Null
        Invoke-FixtureBrowser @('click', '[data-cue-id="cue-1"][data-transcript-action="quick-fix-line"]') | Out-Null
        $editable = Invoke-FixtureScript $geometryScript
        Invoke-FixtureBrowser @('focus', '[data-cue-id="cue-1"][data-token-index="0"]') | Out-Null
        Invoke-FixtureBrowser @('press', 'Tab') | Out-Null
        $focusedToken = Invoke-FixtureScript 'document.activeElement.dataset.tokenIndex'
        if ($focusedToken -ne '1') { throw 'Keyboard order no longer follows logical source order' }
        Invoke-FixtureBrowser @('screenshot', (Join-Path $PSScriptRoot "$language-$width-edit.png")) | Out-Null
        $results += @{language=$language; width=$width; normal=$normal; editable=$editable; nextKeyboardToken=$focusedToken}
    }
}
Invoke-FixtureBrowser @('set', 'viewport', '320', '800') | Out-Null
Invoke-FixtureBrowser @('open', 'http://127.0.0.1:8772/?lang=ara') | Out-Null
Invoke-FixtureBrowser @('wait', '.toks') | Out-Null
Invoke-FixtureBrowser @('click', '[data-cue-id="cue-1"][data-transcript-action="quick-fix-line"]') | Out-Null
Invoke-FixtureBrowser @('click', '[data-cue-id="cue-1"][data-token-index="0"]') | Out-Null
Invoke-FixtureBrowser @('fill', '[data-quick-fix-input]', 'An unfinished correction with enough words to exercise horizontal input scrolling.') | Out-Null
$before = Invoke-FixtureScript @'
(() => {
  const input = document.querySelector('[data-quick-fix-input]');
  input.setSelectionRange(3, 12, 'backward');
  input.scrollLeft = 120;
  document.querySelector('.scroll').scrollTop = 110;
  window.editorSnapshot = () => { const input = document.querySelector('[data-quick-fix-input]'); return {
    text: input.value, focused: document.activeElement === input, start: input.selectionStart, end: input.selectionEnd,
    direction: input.selectionDirection, inputScroll: input.scrollLeft, panelScroll: document.querySelector('.scroll').scrollTop
  }; };
  window.editorBefore = window.editorSnapshot();
  return window.editorBefore;
})()
'@
Invoke-FixtureBrowser @('screenshot', (Join-Path $PSScriptRoot 'editor-before.png')) | Out-Null
$after = Invoke-FixtureScript @'
(async () => {
  const input = document.querySelector('[data-quick-fix-input]');
  window.reviewPatchMetadata();
  const deadline = Date.now() + 3000;
  while (input === document.querySelector('[data-quick-fix-input]')) {
    if (Date.now() > deadline) throw Error('Metadata update did not rebuild the editor');
    await new Promise(requestAnimationFrame);
  }
  const after = window.editorSnapshot();
  if (JSON.stringify(after) !== JSON.stringify(window.editorBefore)) throw Error('Editor changed: ' + JSON.stringify(after));
  return after;
})()
'@
Invoke-FixtureBrowser @('screenshot', (Join-Path $PSScriptRoot 'editor-after.png')) | Out-Null
Invoke-FixtureBrowser @('focus', '[data-transcript-search]') | Out-Null
$outside = Invoke-FixtureScript @'
(async () => {
  const search = document.querySelector('[data-transcript-search]');
  const input = document.querySelector('[data-quick-fix-input]');
  window.reviewPatchMetadata();
  const deadline = Date.now() + 3000;
  while (input === document.querySelector('[data-quick-fix-input]')) {
    if (Date.now() > deadline) throw Error('Second metadata update did not rebuild the editor');
    await new Promise(requestAnimationFrame);
  }
  if (document.activeElement !== search) throw Error('Update stole focus from search');
  if (document.querySelector('[data-quick-fix-input]').value !== window.editorBefore.text) throw Error('Draft changed');
  return {searchRetainsFocus: true, draftRetained: true};
})()
'@
@{scope='Actual side-panel source/CSS, fake transport; not a loaded YouTube extension'; rtl=$results; editor=@{before=$before; after=$after; outside=$outside}} |
    ConvertTo-Json -Depth 20 | Set-Content -LiteralPath (Join-Path $PSScriptRoot 'extension-browser-results.json') -Encoding utf8
Write-Output 'PASS: Arabic/Hebrew, 320/360px, mixed text/punctuation, normal/editable geometry and keyboard order; async editor draft/focus/selection/input and panel scroll; no focus stealing.'
