<section class="section mk-wrap product-demo" id="demo" aria-label="Interactive product preview">
    <div class="mk">
        <div class="mk-bar">
            <div class="mk-dots" aria-hidden="true"><span></span><span></span><span></span></div>
            <span class="mk-url">Your YouTube study space</span>
            <span class="demo-label">Interactive preview</span>
            <span class="mk-ext" aria-hidden="true">Aa</span>
        </div>
        <div class="demo-tabs" role="tablist" aria-label="Explore the extension" hidden>
            <button type="button" role="tab" id="preview-tab-generate" aria-controls="preview-generate" aria-selected="true">01 <span>Generate subtitles</span></button>
            <button type="button" role="tab" id="preview-tab-lyrics" aria-controls="preview-lyrics" aria-selected="false" tabindex="-1">02 <span>Bring your lyrics</span></button>
            <button type="button" role="tab" id="preview-tab-study" aria-controls="preview-study" aria-selected="false" tabindex="-1">03 <span>Explore a word</span></button>
        </div>
        <div class="demo-scene" id="preview-generate">
            <div class="demo-sidebar">
                <p class="eyebrow">01 / Generate subtitles</p>
                <h2>A video becomes<br> a place to learn.</h2>
                <dl class="demo-settings">
                    <div><dt>Subtitles</dt><dd>Spanish</dd></div>
                    <div><dt>Translation</dt><dd>English</dd></div>
                    <div><dt>AI model</dt><dd>Luna</dd></div>
                </dl>
                <button type="button" class="button-accent" data-preview-toggle aria-controls="preview-subtitles" aria-expanded="true" data-open-label="Show example subtitles" data-close-label="Reset preview" hidden>Reset preview</button>
                <p class="demo-help">Choose your languages and model. In the extension, select Generate to begin.</p>
            </div>
            <div class="demo-player">
                <p class="demo-video-label">An evening session <span>Spanish → English</span></p>
                <div class="demo-placeholder" aria-hidden="true"><span>Aa</span><p>Your subtitles start here.</p></div>
                <div class="demo-result" id="preview-subtitles" aria-live="polite">
                    <p class="demo-result-label">First subtitles ready</p>
                    <div class="mk-subs">
                        <p class="mk-line" lang="es"><span class="mk-tok">Bajo</span><span class="mk-tok">la</span><span class="mk-tok">luna,</span><span class="mk-tok">vuelvo</span><span class="mk-tok">a</span><span class="mk-tok">cantar.</span></p>
                        <p class="mk-trans">Under the moon, I sing again.</p>
                    </div>
                </div>
                <p class="demo-timeline"><span>00:12</span><span class="mk-track" aria-hidden="true"><span></span></span><span>00:16</span></p>
            </div>
        </div>
        <div class="demo-scene" id="preview-lyrics">
            <div class="demo-sidebar">
                <p class="eyebrow">02 / Bring your lyrics</p>
                <h2>The words you know.<br> Back in the song.</h2>
                <div class="demo-paste">
                    <span class="sheet-label">Your pasted lyrics</span>
                    <p lang="es">Bajo la luna,<br>vuelvo a cantar.</p>
                </div>
                <button type="button" class="button-accent" data-preview-toggle aria-controls="preview-corrected" aria-expanded="true" data-open-label="Apply example lyrics" data-close-label="Show original line" hidden>Show original line</button>
                <p class="demo-help">In the extension, paste the complete song lyrics to replace the full track.</p>
            </div>
            <div class="demo-player">
                <p class="demo-video-label">Lyrics correction <span>Existing timing</span></p>
                <div class="demo-original">
                    <span class="demo-result-label">Before correction</span>
                    <p class="demo-lyric" lang="es">Bajo la <span class="wrong-word">una</span>,<br>vuelvo a cantar.</p>
                </div>
                <div class="demo-result" id="preview-corrected" aria-live="polite">
                    <p class="demo-result-label">With your lyrics</p>
                    <p class="demo-lyric" lang="es">Bajo la <span class="correct-word">luna</span>,<br>vuelvo a cantar.</p>
                    <p class="mk-trans">Under the moon, I sing again.</p>
                </div>
                <p class="demo-timeline"><span>00:12</span><span class="mk-track" aria-hidden="true"><span></span></span><span>00:16</span></p>
            </div>
        </div>
        <div class="demo-scene" id="preview-study">
            <div class="demo-sidebar">
                <p class="eyebrow">03 / Explore a word</p>
                <h2>Stay with the video.<br> Get the meaning.</h2>
                <p>Open a word card for an explanation in context. Hide the translation when you want to test your understanding.</p>
                <button type="button" class="button-secondary" data-preview-toggle aria-controls="preview-translation" aria-expanded="true" data-open-label="Reveal translation" data-close-label="Hide translation" hidden>Hide translation</button>
                <p class="demo-help">Try selecting <strong>luna</strong> in the subtitle line.</p>
            </div>
            <div class="demo-player">
                <p class="demo-video-label">A word in context <span>Click to explore</span></p>
                <div class="demo-word-space">
                    <div class="mk-card" id="preview-word">
                        <span class="mk-card-tag">Word card</span>
                        <div class="mk-card-word"><strong lang="es">luna</strong><span>loo-nah</span></div>
                        <p><strong>noun · moon</strong><br>“Bajo la luna” means “under the moon.”</p>
                    </div>
                </div>
                <div class="mk-subs">
                    <p class="mk-line" lang="es"><span class="mk-tok">Bajo</span><span class="mk-tok">la</span><button type="button" class="mk-tok mk-tok-hot" data-preview-toggle aria-controls="preview-word" aria-expanded="true">luna</button><span class="mk-tok">vuelvo</span><span class="mk-tok">a</span><span class="mk-tok">cantar.</span></p>
                    <p class="mk-trans" id="preview-translation">Under the moon, I sing again.</p>
                </div>
                <p class="demo-timeline"><span>00:12</span><span class="mk-track" aria-hidden="true"><span></span></span><span>00:16</span></p>
            </div>
        </div>
    </div>
    <p class="mk-caption">Try the controls above. This preview uses example content; generate your own subtitles in the Chrome extension.</p>
</section>
