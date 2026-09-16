@extends('layouts.site')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/site/guide.css') }}?v={{ filemtime(public_path('css/site/guide.css')) }}">
@endpush

@section('content')
    <header class="page-hero guide-hero" id="guide-top">
        <p class="eyebrow">The user guide</p>
        <h1>How To <span class="hl">Use.</span></h1>
        <p>From your first install to your next favorite song. Find the steps for every tool in the extension.</p>
    </header>

    <div class="section guide-layout">
        <aside class="guide-sidebar">
            <details class="guide-contents" open>
                <summary>In this guide</summary>
                <nav aria-label="Guide contents">
                    <p class="eyebrow">Get started</p>
                    <a href="#how-to-install">How to install</a>
                    <a href="#generate-subtitles">Generate subtitles</a>
                    <a href="#transcript">Read the transcript</a>
                    <p class="eyebrow">Make corrections</p>
                    <a href="#replace-lyrics">Replace full lyrics</a>
                    <a href="#fix-a-word">Fix a single word</a>
                    <p class="eyebrow">Study your way</p>
                    <a href="#word-cards">Explore word cards</a>
                    <a href="#study-tools">Display &amp; study tools</a>
                    <a href="#keyboard-shortcuts">Keyboard shortcuts</a>
                    <p class="eyebrow">Manage &amp; get help</p>
                    <a href="#saved-generations">Saved generations &amp; history</a>
                    <a href="#account">Account &amp; usage</a>
                    <a href="#troubleshooting">Troubleshooting</a>
                </nav>
            </details>
            <a class="text-link guide-support" href="{{ route('marketing.support') }}">Contact support <span aria-hidden="true">↗</span></a>
        </aside>

        <article class="guide-copy" aria-label="Extension instructions">
            <section id="how-to-install" aria-labelledby="install-title">
                <p class="eyebrow">01 / Get started</p>
                <h2 id="install-title">How to install</h2>
                <p>Use desktop Chrome. The extension works with public YouTube videos and Shorts; existing captions are not required.</p>

                <h3 id="chrome-web-store">From the Chrome Web Store</h3>
                @if (config('marketing.chrome_extension_url'))
                    <p><a class="button button-accent" href="{{ config('marketing.chrome_extension_url') }}">Open Chrome Web Store <span aria-hidden="true">↗</span></a></p>
                @else
                    <p>The Chrome Web Store link is not available here yet. If you have been given an extension ZIP, use the manual steps below.</p>
                @endif
                <ol>
                    <li>Open the extension’s Chrome Web Store listing in Chrome.</li>
                    <li>Select <strong>Add to Chrome</strong>, review the permissions, and confirm with <strong>Add extension</strong>.</li>
                    <li>Open Chrome’s <strong>Extensions</strong> menu (the puzzle icon) and pin the extension so it stays in your toolbar.</li>
                </ol>

                <h3 id="manual-install">From a downloaded ZIP</h3>
                <ol>
                    <li>Download the extension ZIP provided to you and extract it into a folder you will keep on your computer.</li>
                    <li>Type <code>chrome://extensions</code> into Chrome’s address bar and press Enter.</li>
                    <li>Turn on <strong>Developer mode</strong> in the upper-right corner.</li>
                    <li>Select <strong>Load unpacked</strong> and choose the extracted folder containing <code>manifest.json</code>. Choose the folder, not the ZIP file.</li>
                    <li>Check that the extension is enabled. Open Chrome’s <strong>Extensions</strong> menu and pin it to your toolbar.</li>
                </ol>
                <p>For a manual update, extract the new package into that same folder, then select the extension’s reload button on <code>chrome://extensions</code>. Refresh your open YouTube pages afterward.</p>

                <x-guide-screenshot
                    image="manual-install.png" :width="640" :height="114" :wide="true"
                    title="Load the extracted extension"
                    alt="Chrome Extensions toolbar with Developer mode on and the Load unpacked button visible."
                    :steps="[
                        ['x' => 487, 'y' => 9, 'w' => 147, 'h' => 39, 'cx' => 628, 'cy' => 54, 'text' => 'Turn on Developer mode. Chrome then shows the manual installation buttons.'],
                        ['x' => 21, 'y' => 63, 'w' => 130, 'h' => 41, 'cx' => 164, 'cy' => 83, 'text' => 'Select Load unpacked, then choose the extracted folder containing manifest.json.'],
                    ]"
                />

                <h3>Open the panel and sign in</h3>
                <ol>
                    <li>Open a public YouTube video or Short and select the extension’s toolbar icon. Its side panel opens beside the page.</li>
                    <li>Open <strong>Account</strong> and sign in with the same email and password you use on this website.</li>
                    <li>If you need an account, <a href="{{ route('register') }}">create one</a>. Generation requires an active plan and available minutes; you can check these in Account or your <a href="{{ route('dashboard') }}">dashboard</a>.</li>
                    <li>Return to <strong>Watch</strong> to generate subtitles. After installing or updating, refresh any YouTube tab that was already open.</li>
                </ol>
                <p class="guide-example-note">The extension screenshots below use an example song and sample lyrics. Select an image to view it at full size.</p>
            </section>

            <section id="generate-subtitles" aria-labelledby="generate-title">
                <p class="eyebrow">02 / Watch</p>
                <h2 id="generate-title">Generate subtitles</h2>
                <ol>
                    <li>Open your video and the extension’s <strong>Watch</strong> tab. Check that the video at the top of the panel is the one you want.</li>
                    <li>Select <strong>Change</strong> beside the language pair. Choose the <strong>Video language</strong>, or use <strong>Auto detect</strong> when you are unsure. Choose your <strong>Translation language</strong> separately.</li>
                    <li>Under <strong>Layers</strong>, choose <strong>Translation</strong> and, if wanted, <strong>Romanization</strong> (a reading in Latin letters, where available).</li>
                    <li>Choose an <strong>AI model</strong>: <strong>Transcriber</strong> for language understanding and detail, or <strong>Transcriber Spark</strong> for speed. The website calls the latter Transcriber-Spark.</li>
                    <li>Select <strong>Generate subtitles</strong>. Review the video, options, and minute-use notice, then choose <strong>Start generation</strong>. Choose <strong>Go back</strong> to change your settings.</li>
                    <li>Follow the named progress stages. Subtitles appear on the video as they become ready. You can close the side panel while generation continues.</li>
                </ol>
                <p>Editing and interactive word cards become available once the track is complete. If your plan’s simultaneous-video slots are occupied, an accepted generation waits in the queue.</p>
                <aside class="guide-note"><strong>Before you start:</strong> a new generation can use plan minutes for the whole video. Cancelling after processing starts does not restore those minutes. Review the confirmation before proceeding.</aside>
                <p>To try different languages, layers, or a model on a video with a completed track, choose <strong>Generate again</strong> above the transcript. Changing next-generation settings does not change an existing saved track.</p>
                <x-guide-screenshot
                    image="generate-subtitles.png" :width="460" :height="760"
                    title="Set up your generation"
                    alt="Watch tab showing Spanish to English, Translation enabled, the Transcriber model, and Generate subtitles."
                    :steps="[
                        ['x' => 375, 'y' => 241, 'w' => 61, 'h' => 34, 'cx' => 365, 'cy' => 244, 'text' => 'Use Change to choose the video and translation languages.'],
                        ['x' => 27, 'y' => 337, 'w' => 247, 'h' => 43, 'cx' => 288, 'cy' => 358, 'text' => 'Choose the layers you want. Romanization adds a reading in Latin letters where available.'],
                        ['x' => 293, 'y' => 416, 'w' => 140, 'h' => 44, 'cx' => 281, 'cy' => 438, 'text' => 'Pick Transcriber or Transcriber Spark for this generation.'],
                        ['x' => 12, 'y' => 609, 'w' => 436, 'h' => 54, 'cx' => 27, 'cy' => 608, 'text' => 'Generate subtitles opens a confirmation. Review it before choosing Start generation.'],
                    ]"
                />
            </section>

            <section id="transcript" aria-labelledby="transcript-title">
                <p class="eyebrow">03 / Watch</p>
                <h2 id="transcript-title">Read the transcript</h2>
                <p>After generation, Watch shows a timed transcript beside the video. The current line is highlighted as the video plays.</p>
                <ul>
                    <li>Use <strong>Search transcript</strong> to find a line.</li>
                    <li>Select <strong>Jump</strong> on a line to seek the video to that point.</li>
                    <li>Select <strong>Copy</strong> to copy the line’s text.</li>
                    <li>Use <strong>Generate again</strong> or <strong>Lyric correction</strong> above search to open those tools. <strong>Back to transcript</strong> returns to the reading view.</li>
                </ul>
                <p>Translations and pronunciation readings depend on the layers available in that generation. Choosing the same video and translation language does not produce a second-language translation.</p>
                <x-guide-screenshot
                    image="transcript.png" :width="428" :height="491"
                    title="Find your way around Watch"
                    alt="A saved Spanish to English transcript with search, Edit, Jump, and Copy controls on each line."
                    :steps="[
                        ['x' => 3, 'y' => 21, 'w' => 422, 'h' => 42, 'cx' => 408, 'cy' => 20, 'text' => 'Saved generation switches between completed tracks for this video.'],
                        ['x' => 3, 'y' => 146, 'w' => 422, 'h' => 42, 'cx' => 408, 'cy' => 146, 'text' => 'Search transcript helps you find the line you want to read or correct.'],
                        ['x' => 50, 'y' => 242, 'w' => 188, 'h' => 32, 'cx' => 252, 'cy' => 258, 'text' => 'Edit corrects a word, Jump seeks to the line, and Copy copies its text.'],
                    ]"
                />
            </section>

            <section id="replace-lyrics" aria-labelledby="lyrics-title">
                <p class="eyebrow">04 / Make corrections</p>
                <h2 id="lyrics-title">Replace full lyrics</h2>
                <p>Use this when you have the complete lyrics for a song and want to replace its generated transcript. Start with a completed generation.</p>
                <ol>
                    <li>In <strong>Watch</strong>, select the track in <strong>Saved generation</strong>, then choose <strong>Lyric correction</strong>.</li>
                    <li>Paste the complete lyrics for this exact version of the song, in order. Include every repeated verse and chorus. Remove section tags such as <code>[Verse]</code>, <code>[Chorus]</code>, and <code>[Bridge]</code>; paste only the words that are sung.</li>
                    <li>Keep the text within the displayed 25,000-character limit. Choose <strong>Continue</strong> and review the full-track replacement notice.</li>
                    <li>Select <strong>Replace entire track</strong> to confirm. The compact status strip shows progress; choose <strong>View progress</strong> for the stages or <strong>Cancel replacement</strong> while it is running.</li>
                    <li>When replacement finishes, play several lines to check the wording and timing. Translations, enabled pronunciation readings, and word data are rebuilt with the corrected text.</li>
                </ol>
                <aside class="guide-note"><strong>This replaces the entire track.</strong> It fits your lyrics to existing timing; it does not fetch lyrics, merge a partial verse, or create new timing from the audio. Keep a copy of text you need to preserve: a completed replacement has no undo.</aside>
                <p>Full lyrics correction uses Transcriber, regardless of the generation’s model, and does not use additional generated-video minutes. Incomplete or wrong lyrics can put words at the wrong times. If needed, use <strong>Generate again</strong> to create fresh subtitles; regeneration can use plan minutes.</p>
                <x-guide-screenshot
                    image="replace-lyrics.png" :width="428" :height="488"
                    title="Review the full replacement"
                    alt="Full lyrics replacement form with example Spanish lyrics and the final Replace entire track confirmation."
                    :steps="[
                        ['x' => 12, 'y' => 224, 'w' => 404, 'h' => 152, 'cx' => 400, 'cy' => 224, 'text' => 'Paste the complete lyrics in order, including repeated verses. Choose Continue below the text.'],
                        ['x' => 12, 'y' => 378, 'w' => 404, 'h' => 99, 'cx' => 400, 'cy' => 378, 'text' => 'After Continue, this notice appears. Check it before selecting Replace entire track; a completed replacement has no undo.'],
                    ]"
                />
            </section>

            <section id="fix-a-word" aria-labelledby="word-fix-title">
                <p class="eyebrow">05 / Make corrections</p>
                <h2 id="word-fix-title">Fix a single word</h2>
                <p>For a small mistake, edit one word in a completed transcript instead of replacing the full lyrics.</p>
                <ol>
                    <li>Find the line in <strong>Watch</strong>, using transcript search if needed.</li>
                    <li>Select <strong>Edit</strong> on that line, then select the word you want to correct.</li>
                    <li>Enter the replacement in <strong>Correct word</strong> and choose <strong>Save correction</strong>. Use <strong>Cancel</strong> to discard an unsaved edit.</li>
                    <li>Wait for the update, then check the line. Select <strong>Done</strong> to leave word selection.</li>
                </ol>
                <p>The correction keeps the line’s timing and refreshes its translation, enabled pronunciation readings, and word cards. It does not use additional generated-video minutes. Wait for generation or a lyrics replacement to finish before making another correction.</p>
                <x-guide-screenshot
                    image="fix-a-word.png" :width="428" :height="283"
                    title="Correct just one word"
                    alt="Single-word editor correcting the example transcript word una to luna while keeping the surrounding line."
                    :steps="[
                        ['x' => 118, 'y' => 6, 'w' => 41, 'h' => 27, 'cx' => 139, 'cy' => -5, 'text' => 'Select Edit on a line, then select the mistaken word. Here, una should be luna.'],
                        ['x' => 60, 'y' => 95, 'w' => 359, 'h' => 42, 'cx' => 42, 'cy' => 116, 'text' => 'Type the corrected word in Correct word, keeping any punctuation you need.'],
                        ['x' => 60, 'y' => 197, 'w' => 119, 'h' => 32, 'cx' => 42, 'cy' => 213, 'text' => 'Save correction updates the line and its word data. Review the result, then select Done.'],
                    ]"
                />
            </section>

            <section id="word-cards" aria-labelledby="cards-title">
                <p class="eyebrow">06 / Learn in context</p>
                <h2 id="cards-title">Explore word cards</h2>
                <ol>
                    <li>Play a completed track and move over a word in the subtitles on the video to preview the available word information.</li>
                    <li>Click the word to pin its detail card. New details may take a moment to load the first time you request them.</li>
                    <li>Read the meaning, pronunciation, and usage information available for that word in its sentence. Click another word to explore it.</li>
                </ol>
                <p>In <strong>Study → Active recall</strong>, enable <strong>Pause video on word hover</strong> if you want time to read. Word cards explain the current video’s words; saved vocabulary and spaced-repetition review are not currently available.</p>
                {{-- Word-card screenshots or video go here. --}}
            </section>

            <section id="study-tools" aria-labelledby="study-title">
                <p class="eyebrow">07 / Study</p>
                <h2 id="study-title">Display &amp; study tools</h2>
                <h3>Place and style the subtitles</h3>
                <p>Open <strong>Study → Caption display</strong>. Use <strong>Show overlay</strong> to show or hide the subtitles on the video. Choose <strong>Bottom</strong>, <strong>Top</strong>, or <strong>Compact</strong> under Position, then adjust <strong>Caption size</strong>, <strong>Density</strong>, and <strong>Contrast</strong>. <strong>Word gloss</strong> controls available short word meanings.</p>
                <p>To move subtitles freely, uncheck <strong>Attach subtitles to video</strong> and drag the <strong>Move subtitles</strong> handle. You can also focus the handle and use the arrow keys; hold Shift for larger steps. Check attachment again to return to the selected position. The free position lasts while the overlay is open.</p>

                <h3>Adjust timing</h3>
                <p>If the subtitles appear too early or late, adjust <strong>Timing</strong> with the slider or seconds field. The range is −10 to +10 seconds in 0.1-second steps. Use <strong>Reset</strong> to return to zero. This changes playback display timing, not the words or the stored cue timings.</p>
                <x-guide-screenshot
                    image="study-display.png" :width="418" :height="459"
                    title="Place captions and adjust timing"
                    alt="Study caption display settings, including attachment, position, size, density, contrast, and the timing adjustment."
                    :steps="[
                        ['x' => 11, 'y' => 76, 'w' => 396, 'h' => 44, 'cx' => 349, 'cy' => 78, 'text' => 'Uncheck Attach subtitles to video to move them freely. Check it again to return to your chosen position.'],
                        ['x' => 266, 'y' => 120, 'w' => 140, 'h' => 185, 'cx' => 254, 'cy' => 133, 'text' => 'Choose a position, then adjust the size, spacing, and contrast to suit the video.'],
                        ['x' => 11, 'y' => 398, 'w' => 396, 'h' => 49, 'cx' => 351, 'cy' => 397, 'text' => 'Use Timing to shift subtitle playback. Reset returns the offset to zero.'],
                    ]"
                />

                <h3>Practice listening before reading</h3>
                <p>Under <strong>Active recall</strong>, turn on <strong>Blur source words</strong>, <strong>Blur romanization</strong>, or <strong>Blur translation</strong>. Hover or focus the part you want to reveal when you need a hint. Source-word reveals include that word’s reading when available; the full reading and translation can be revealed separately.</p>
                <p>Enable <strong>Pause video on word hover</strong> to pause while inspecting a word. These are display preferences; they do not start another generation.</p>
                <x-guide-screenshot
                    image="study-recall.png" :width="418" :height="212"
                    title="Listen first, reveal when ready"
                    alt="Active recall controls for blurring source words, romanization, and translation, plus pause on word hover."
                    :steps="[
                        ['x' => 360, 'y' => 40, 'w' => 48, 'h' => 115, 'cx' => 346, 'cy' => 51, 'text' => 'Turn on a blur option to hide that layer. Hover or focus it when you want a hint.'],
                        ['x' => 360, 'y' => 164, 'w' => 48, 'h' => 31, 'cx' => 346, 'cy' => 179, 'text' => 'Pause on word hover gives you time to read a word card while the video waits.'],
                    ]"
                />

                <h3>Reset this device</h3>
                <p><strong>Study → This device → Clear local state</strong> resets local settings and remembered tracks and signs the extension out. It does not delete your account’s server-side jobs or cancel your subscription. Sign in again to access retained generations.</p>
            </section>

            <section id="keyboard-shortcuts" aria-labelledby="shortcuts-title">
                <p class="eyebrow">08 / Keep watching</p>
                <h2 id="shortcuts-title">Keyboard shortcuts</h2>
                <p>Enable <strong>Study → Keyboard shortcuts → Shortcuts enabled</strong>. Use these while the YouTube page has focus. Shortcuts do not run while you are typing in an input or editing text. On Mac, Alt is the Option key.</p>
                <table class="guide-shortcuts">
                    <caption>Shortcuts for the current video</caption>
                    <thead><tr><th scope="col">Shortcut</th><th scope="col">Action</th></tr></thead>
                    <tbody>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>R</kbd></td><td>Replay the current line</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>←</kbd></td><td>Jump to the previous line</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>→</kbd></td><td>Jump to the next line</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>T</kbd></td><td>Show or hide translation</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>B</kbd></td><td>Blur or reveal source words</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>A</kbd></td><td>Toggle pause on word hover</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>X</kbd></td><td>Focus the side-panel transcript</td></tr>
                        <tr><td><kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>C</kbd></td><td>Copy the current line</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="saved-generations" aria-labelledby="saved-title">
                <p class="eyebrow">09 / Come back to a video</p>
                <h2 id="saved-title">Saved generations &amp; history</h2>
                <p>Use <strong>Saved generation</strong> at the top of the transcript to switch between completed tracks for the current video. The language pair and model help identify each one. Switching updates the transcript and video subtitles without generating again.</p>
                <ul>
                    <li><strong>Refresh saved generations</strong> checks for the latest available tracks. Switching and deletion wait while generation or a lyrics change is active.</li>
                    <li><strong>Delete selected generation</strong> in the dropdown removes that generation and its stored track after confirmation. It switches to another available track, or returns to setup if none remain.</li>
                    <li>The <strong>History</strong> tab lists your latest 25 jobs, grouped into Videos and Shorts. Use <strong>Open video</strong> to return to YouTube. Available completed generations are recovered for that video.</li>
                    <li>For a failed job, open the video, review Watch settings, and choose Generate. The failed job’s options are not automatically restored.</li>
                    <li>Use <strong>Cancel generation</strong> on a queued or running job when you want to stop it. Read the minute-use notice before confirming.</li>
                </ul>
                <p>Completed tracks are retained for 30 days. Reopening a retained track uses no new generation minutes. Deleting a completed track does not refund used minutes; creating a new generation can use minutes again.</p>
                <p>You can also delete individual jobs or clear all jobs from the website dashboard. If a deleted track is still visible on an already-open YouTube page, refresh the page.</p>
            </section>

            <section id="account" aria-labelledby="account-title">
                <p class="eyebrow">10 / Your account</p>
                <h2 id="account-title">Account &amp; usage</h2>
                <p>The extension’s <strong>Account</strong> tab shows your sign-in status, plan, used and reserved minutes, remaining balance, and reset date. Reserved minutes belong to jobs that have not finished yet.</p>
                <p>Open the <a href="{{ route('dashboard') }}">website dashboard</a> for billing, connected extension installs, and recent jobs. The website may need a separate sign-in; use the same account as the extension.</p>
                <ul>
                    <li><strong>Refresh status</strong> updates the dashboard snapshot.</li>
                    <li><strong>Manage billing</strong> or <strong>Manage or cancel subscription</strong> opens the billing portal for payment and subscription changes.</li>
                    <li>A job’s <strong>Support ID</strong> opens its status, stages, timings, language pair, and available failure details. Include this ID when reporting a generation issue.</li>
                    <li><strong>Sign out</strong> disconnects the extension session. Signing out or uninstalling does not cancel your subscription.</li>
                    <li><strong>Delete account</strong> permanently removes account data and cancels an active subscription. Read the dashboard’s confirmation carefully before proceeding.</li>
                </ul>
            </section>

            <section id="troubleshooting" aria-labelledby="help-title">
                <p class="eyebrow">11 / Get help</p>
                <h2 id="help-title">Troubleshooting</h2>
                <dl class="guide-help">
                    <div><dt>The panel says “No video here”</dt><dd>Switch to a public YouTube video or Short in the same window. After installing or updating, refresh the page and reopen the panel.</dd></div>
                    <div><dt>I cannot start generation</dt><dd>Check Account for your sign-in, active plan, and remaining minutes. Wait for an available queue slot if your plan’s limit is reached, and follow any message shown in Watch.</dd></div>
                    <div><dt>I cannot see subtitles</dt><dd>Check Study → Show overlay, play a part of the video with speech, and confirm the selected saved generation. Use Refresh saved generations if needed.</dd></div>
                    <div><dt>The words or timing are wrong</dt><dd>Try a <a href="#fix-a-word">single-word correction</a>, <a href="#replace-lyrics">full lyrics replacement</a>, or the <a href="#study-tools">Timing control</a>. Check the result against the video. For fresh subtitles, use Generate again and review the minute-use notice.</dd></div>
                    <div><dt>Something failed or looks broken</dt><dd>Open the job’s Support ID in your dashboard. Note the failure code, video, language pair, what you expected, and steps to reproduce the problem. <a href="{{ route('marketing.support') }}">Contact support</a> with those details; avoid sending passwords or full transcripts.</dd></div>
                </dl>
            </section>
            <a class="text-link strong-link" href="#guide-top">Back to the top <span aria-hidden="true">↑</span></a>
        </article>
    </div>
@endsection
