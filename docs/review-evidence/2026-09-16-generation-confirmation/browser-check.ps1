# Start ../2026-09-15-remediation/extension-fixture.cjs first. All transport is fake.
$ErrorActionPreference = 'Stop'
function Invoke-ConfirmationBrowser([string[]] $BrowserArguments) {
    $response = (& agent-browser --session remediation-confirmation --json @BrowserArguments | ConvertFrom-Json)
    if ($LASTEXITCODE -ne 0 -or -not $response.success) { throw ($response | ConvertTo-Json -Depth 15) }
    return $response.data
}
function Invoke-ConfirmationScript([string] $Script) {
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Script))
    return (Invoke-ConfirmationBrowser @('eval', '-b', $encoded)).result
}
$results = @()
foreach ($width in @(320, 360)) {
    Invoke-ConfirmationBrowser @('set', 'viewport', "$width", '800') | Out-Null
    Invoke-ConfirmationBrowser @('open', 'http://127.0.0.1:8772/?generation=1') | Out-Null
    Invoke-ConfirmationBrowser @('wait', '--fn', '!document.querySelector("[data-action=generate]").disabled') | Out-Null
    Invoke-ConfirmationBrowser @('click', '[data-action="generate"]') | Out-Null
    $opened = Invoke-ConfirmationScript @'
(() => {
  const dialog = document.querySelector('dialog');
  const rect = dialog.getBoundingClientRect();
  const requests = window.reviewCalls.filter(call => call.type === 'panel.generateSubtitles').length;
  if (!dialog.open || !dialog.matches(':modal')) throw Error('Confirmation is not a native modal');
  if (document.activeElement.dataset.action !== 'cancel-generation-confirmation') throw Error('Initial focus is not Go back');
  if (rect.left < 0 || rect.right > innerWidth || rect.top < 0 || rect.bottom > innerHeight || dialog.scrollWidth > dialog.clientWidth) throw Error('Dialog overflow');
  if (requests !== 0) throw Error('Generation started before consent');
  if (!dialog.textContent.includes("minutes for the entire video will still be used and won't be refunded")) throw Error('Missing full-video minutes warning');
  if (!dialog.textContent.includes('Estimated usage: 4 plan minutes')) throw Error('Missing estimated full-video usage');
  return {width: innerWidth, left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom,
    initialFocus: document.activeElement.textContent, requests, text: dialog.innerText};
})()
'@
    Invoke-ConfirmationBrowser @('screenshot', (Join-Path $PSScriptRoot "confirmation-$width.png")) | Out-Null
    Invoke-ConfirmationBrowser @('press', 'Tab') | Out-Null
    $nextFocus = Invoke-ConfirmationScript 'document.activeElement.dataset.action'
    if ($nextFocus -ne 'confirm-generation') { throw 'Tab did not reach Start generation' }
    Invoke-ConfirmationBrowser @('press', 'Shift+Tab') | Out-Null
    $previousFocus = Invoke-ConfirmationScript 'document.activeElement.dataset.action'
    if ($previousFocus -ne 'cancel-generation-confirmation') { throw 'Shift+Tab did not return to Go back' }
    Invoke-ConfirmationBrowser @('press', 'Escape') | Out-Null
    $escaped = Invoke-ConfirmationScript @'
(() => {
  const open = document.querySelector('dialog').open;
  const focus = document.activeElement.dataset.action;
  const requests = window.reviewCalls.filter(call => call.type === 'panel.generateSubtitles').length;
  if (open || focus !== 'generate' || requests !== 0) throw Error('Escape did not safely return to Generate');
  return {open, focus, requests};
})()
'@
    Invoke-ConfirmationBrowser @('click', '[data-action="generate"]') | Out-Null
    Invoke-ConfirmationBrowser @('click', '[data-action="cancel-generation-confirmation"]') | Out-Null
    Invoke-ConfirmationScript 'if (document.querySelector("dialog").open || window.reviewCalls.some(c => c.type === "panel.generateSubtitles")) throw Error("Decline sent a request"); true' | Out-Null
    $results += @{width=$width; opened=$opened; nextFocus=$nextFocus; previousFocus=$previousFocus; escaped=$escaped}
}

Invoke-ConfirmationScript 'window.reviewHoldGeneration = true' | Out-Null
Invoke-ConfirmationBrowser @('click', '[data-action="generate"]') | Out-Null
Invoke-ConfirmationBrowser @('click', '[data-action="confirm-generation"]') | Out-Null
$busy = Invoke-ConfirmationScript @'
(() => {
  const generate = document.querySelector('[data-action=generate]');
  generate.click(); document.querySelector('[data-action=confirm-generation]').click();
  const requests = window.reviewCalls.filter(call => call.type === 'panel.generateSubtitles');
  if (!generate.disabled || document.querySelector('dialog').open || requests.length !== 1) throw Error('Duplicate generation request while busy');
  return {disabled: generate.disabled, requestCount: requests.length, request: requests[0]};
})()
'@
Invoke-ConfirmationScript 'window.reviewHoldGeneration = false; window.reviewResolveGeneration({ok:false,error:"Fixture generation failed. Try again."}); true' | Out-Null
Invoke-ConfirmationBrowser @('wait', '--fn', '!document.querySelector("[data-action=generate]").disabled') | Out-Null
Invoke-ConfirmationBrowser @('click', '[data-action="generate"]') | Out-Null
Invoke-ConfirmationScript 'if (!document.querySelector("dialog").open || window.reviewCalls.filter(c => c.type === "panel.generateSubtitles").length !== 1) throw Error("Retry skipped consent"); true' | Out-Null
Invoke-ConfirmationBrowser @('screenshot', (Join-Path $PSScriptRoot 'confirmation-retry-360.png')) | Out-Null
Invoke-ConfirmationBrowser @('press', 'Escape') | Out-Null

$invalidated = Invoke-ConfirmationScript @'
(async () => {
  const changes = [
    ['duration', () => window.reviewState.pageVideoDurationSeconds = 240],
    ['settings', () => window.reviewState.settings.aiProvider = 'cerebras'],
    ['video', () => window.reviewState.pageStatus.videoId = 'M7lc1UVf-VE'],
    ['account', () => window.reviewState.accountState.id = 'another-fixture-user'],
  ];
  const results = [];
  for (const [change, update] of changes) {
    document.querySelector('[data-action=generate]').click();
    if (!document.querySelector('dialog').open) throw Error('Could not open before ' + change);
    update(); window.reviewRefresh();
    const deadline = Date.now() + 2000;
    while (document.querySelector('dialog').open && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 20));
    if (document.querySelector('dialog').open) throw Error('Stale consent remained for ' + change);
    document.querySelector('[data-action=confirm-generation]').click();
    if (window.reviewCalls.filter(c => c.type === 'panel.generateSubtitles').length !== 1) throw Error('Stale consent submitted for ' + change);
    results.push({change, open: false, requestCount: 1});
  }
  return results;
})()
'@

Invoke-ConfirmationBrowser @('open', 'http://127.0.0.1:8772/?lang=ara') | Out-Null
Invoke-ConfirmationBrowser @('wait', '.toks') | Out-Null
Invoke-ConfirmationBrowser @('click', '[data-action="toggle-setup"]') | Out-Null
Invoke-ConfirmationBrowser @('click', '[data-action="generate"]') | Out-Null
$generateAgain = Invoke-ConfirmationScript 'if (!document.querySelector("dialog").open || window.reviewCalls.some(c => c.type === "panel.generateSubtitles")) throw Error("Generate again skipped consent"); ({open:true,requestCount:0})'
@{viewports=$results; busy=$busy; invalidated=$invalidated; generateAgain=$generateAgain} | ConvertTo-Json -Depth 15 | Set-Content (Join-Path $PSScriptRoot 'browser-results.json') -Encoding utf8
Invoke-ConfirmationBrowser @('close') | Out-Null
Write-Output 'Generation confirmation browser checks passed.'
