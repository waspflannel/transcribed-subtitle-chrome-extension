@extends('layouts.site')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/site/guide.css') }}?v={{ filemtime(public_path('css/site/guide.css')) }}">
@endpush

@section('content')
    <header class="page-hero guide-hero" id="guide-top">
        <p class="eyebrow">{{ __('The user guide') }}</p>
        <h1>{!! strtr(e(__('How To :slot1:Use.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h1>
        <p>{{ __('From your first install to your next favorite song. Find the steps for every tool in the extension.') }}</p>
    </header>

    <div class="section guide-layout">
        <aside class="guide-sidebar">
            <details class="guide-contents" open>
                <summary>{{ __('In this guide') }}</summary>
                <nav aria-label="{{ __('Guide contents') }}">
                    <p class="eyebrow">{{ __('Get started') }}</p>
                    <a href="#how-to-install">{{ __('How to install') }}</a>
                    <a href="#generate-subtitles">{{ __('Generate subtitles') }}</a>
                    <a href="#transcript">{{ __('Read the transcript') }}</a>
                    <p class="eyebrow">{{ __('Make corrections') }}</p>
                    <a href="#replace-lyrics">{{ __('Replace full lyrics') }}</a>
                    <a href="#fix-a-word">{{ __('Fix a single word') }}</a>
                    <p class="eyebrow">{{ __('Study your way') }}</p>
                    <a href="#word-cards">{{ __('Explore word cards') }}</a>
                    <a href="#study-tools">{{ __('Display & study tools') }}</a>
                    <a href="#keyboard-shortcuts">{{ __('Keyboard shortcuts') }}</a>
                    <p class="eyebrow">{{ __('Manage & get help') }}</p>
                    <a href="#saved-generations">{{ __('Saved generations & history') }}</a>
                    <a href="#account">{{ __('Account & usage') }}</a>
                    <a href="#troubleshooting">{{ __('Troubleshooting') }}</a>
                </nav>
            </details>
            <a class="text-link guide-support" href="{{ \App\Support\WebsiteLocale::route('marketing.support') }}">{!! strtr(e(__('Contact support :slot1:↗:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
        </aside>

        <article class="guide-copy" aria-label="{{ __('Extension instructions') }}">
            <section id="how-to-install" aria-labelledby="install-title">
                <p class="eyebrow">{{ __('01 / Get started') }}</p>
                <h2 id="install-title">{{ __('How to install') }}</h2>
                <p>{{ __('Use desktop Chrome. The extension works with public YouTube videos and Shorts; existing captions are not required.') }}</p>

                <h3 id="chrome-web-store">{{ __('From the Chrome Web Store') }}</h3>
                @if (config('marketing.chrome_extension_url'))
                    <p>{!! strtr(e(__(':slot1:Open Chrome Web Store :slot2:↗:slot3::slot4:')), [':slot1:' => '<a class="button button-accent" href="'.e(config('marketing.chrome_extension_url')).'">', ':slot2:' => '<span aria-hidden="true">', ':slot3:' => '</span>', ':slot4:' => '</a>']) !!}</p>
                @else
                    <p>{{ __('The Chrome Web Store link is not available here yet. If you have been given an extension ZIP, use the manual steps below.') }}</p>
                @endif
                <ol>
                    <li>{{ __('Open the extension’s Chrome Web Store listing in Chrome.') }}</li>
                    <li>{!! strtr(e(__('Select :slot1:Add to Chrome:slot2:, review the permissions, and confirm with :slot3:Add extension:slot4:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Open Chrome’s :slot1:Extensions:slot2: menu (the puzzle icon) and pin the extension so it stays in your toolbar.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ol>

                <h3 id="manual-install">{{ __('From a downloaded ZIP') }}</h3>
                <ol>
                    <li>{{ __('Download the extension ZIP provided to you and extract it into a folder you will keep on your computer.') }}</li>
                    <li>{!! strtr(e(__('Type :slot1: into Chrome’s address bar and press Enter.')), [':slot1:' => '<code>chrome://extensions</code>']) !!}</li>
                    <li>{!! strtr(e(__('Turn on :slot1:Developer mode:slot2: in the upper-right corner.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Load unpacked:slot2: and choose the extracted folder containing :slot3:. Choose the folder, not the ZIP file.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<code>manifest.json</code>']) !!}</li>
                    <li>{!! strtr(e(__('Check that the extension is enabled. Open Chrome’s :slot1:Extensions:slot2: menu and pin it to your toolbar.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ol>
                <p>{!! strtr(e(__('For a manual update, extract the new package into that same folder, then select the extension’s reload button on :slot1:. Refresh your open YouTube pages afterward.')), [':slot1:' => '<code>chrome://extensions</code>']) !!}</p>

                <x-guide-screenshot
                    image="manual-install.png" :width="640" :height="114" :wide="true"
                    title="{{ __('Load the extracted extension') }}"
                    alt="{{ __('Chrome Extensions toolbar with Developer mode on and the Load unpacked button visible.') }}"
                    :steps="[
                        ['x' => 487, 'y' => 9, 'w' => 147, 'h' => 39, 'cx' => 628, 'cy' => 54, 'text' => __('Turn on Developer mode. Chrome then shows the manual installation buttons.')],
                        ['x' => 21, 'y' => 63, 'w' => 130, 'h' => 41, 'cx' => 164, 'cy' => 83, 'text' => __('Select Load unpacked, then choose the extracted folder containing manifest.json.')],
                    ]"
                />

                <h3>{{ __('Open the panel and sign in') }}</h3>
                <ol>
                    <li>{{ __('Open a public YouTube video or Short and select the extension’s toolbar icon. Its side panel opens beside the page.') }}</li>
                    <li>{!! strtr(e(__('Open :slot1:Account:slot2: and sign in with the same email and password you use on this website.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('If you need an account, :slot1:create one:slot2:. Generation requires an active plan and available minutes; you can check these in Account or your :slot3:dashboard:slot4:.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('register')).'">', ':slot2:' => '</a>', ':slot3:' => '<a href="'.e(route('dashboard')).'">', ':slot4:' => '</a>']) !!}</li>
                    <li>{!! strtr(e(__('Return to :slot1:Watch:slot2: to generate subtitles. After installing or updating, refresh any YouTube tab that was already open.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ol>
                <p class="guide-example-note">{{ __('The extension screenshots below use an example song and sample lyrics. Select an image to view it at full size.') }}</p>
            </section>

            <section id="generate-subtitles" aria-labelledby="generate-title">
                <p class="eyebrow">{{ __('02 / Watch') }}</p>
                <h2 id="generate-title">{{ __('Generate subtitles') }}</h2>
                <ol>
                    <li>{!! strtr(e(__('Open your video and the extension’s :slot1:Watch:slot2: tab. Check that the video at the top of the panel is the one you want.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Change:slot2: beside the language pair. Choose the :slot3:Video language:slot4:, or use :slot5:Auto detect:slot6: when you are unsure. Choose your :slot7:Translation language:slot8: separately.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>', ':slot7:' => '<strong>', ':slot8:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Under :slot1:Layers:slot2:, choose :slot3:Translation:slot4: and, if wanted, :slot5:Romanization:slot6: (a reading in Latin letters, where available).')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Choose an :slot1:AI model:slot2:: :slot3:Transcriber:slot4: for language understanding and detail, or :slot5:Transcriber Spark:slot6: for speed. The website calls the latter Transcriber-Spark.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Generate subtitles:slot2:. Review the video, options, and minute-use notice, then choose :slot3:Start generation:slot4:. Choose :slot5:Go back:slot6: to change your settings.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                    <li>{{ __('Follow the named progress stages. Subtitles appear on the video as they become ready. You can close the side panel while generation continues.') }}</li>
                </ol>
                <p>{{ __('Editing and interactive word cards become available once the track is complete. If your plan’s simultaneous-video slots are occupied, an accepted generation waits in the queue.') }}</p>
                <aside class="guide-note">{!! strtr(e(__(':slot1:Before you start::slot2: a new generation can use plan minutes for the whole video. Cancelling after processing starts does not restore those minutes. Review the confirmation before proceeding.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</aside>
                <p>{!! strtr(e(__('To try different languages, layers, or a model on a video with a completed track, choose :slot1:Generate again:slot2: above the transcript. Changing next-generation settings does not change an existing saved track.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <x-guide-screenshot
                    image="generate-subtitles.png" :width="460" :height="760"
                    title="{{ __('Set up your generation') }}"
                    alt="{{ __('Watch tab showing Spanish to English, Translation enabled, the Transcriber model, and Generate subtitles.') }}"
                    :steps="[
                        ['x' => 375, 'y' => 241, 'w' => 61, 'h' => 34, 'cx' => 365, 'cy' => 244, 'text' => __('Use Change to choose the video and translation languages.')],
                        ['x' => 27, 'y' => 337, 'w' => 247, 'h' => 43, 'cx' => 288, 'cy' => 358, 'text' => __('Choose the layers you want. Romanization adds a reading in Latin letters where available.')],
                        ['x' => 293, 'y' => 416, 'w' => 140, 'h' => 44, 'cx' => 281, 'cy' => 438, 'text' => __('Pick Transcriber or Transcriber Spark for this generation.')],
                        ['x' => 12, 'y' => 609, 'w' => 436, 'h' => 54, 'cx' => 27, 'cy' => 608, 'text' => __('Generate subtitles opens a confirmation. Review it before choosing Start generation.')],
                    ]"
                />
            </section>

            <section id="transcript" aria-labelledby="transcript-title">
                <p class="eyebrow">{{ __('03 / Watch') }}</p>
                <h2 id="transcript-title">{{ __('Read the transcript') }}</h2>
                <p>{{ __('After generation, Watch shows a timed transcript beside the video. The current line is highlighted as the video plays.') }}</p>
                <ul>
                    <li>{!! strtr(e(__('Use :slot1:Search transcript:slot2: to find a line.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Jump:slot2: on a line to seek the video to that point.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Copy:slot2: to copy the line’s text.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Use :slot1:Generate again:slot2: or :slot3:Lyric correction:slot4: above search to open those tools. :slot5:Back to transcript:slot6: returns to the reading view.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                </ul>
                <p>{{ __('Translations and pronunciation readings depend on the layers available in that generation. Choosing the same video and translation language does not produce a second-language translation.') }}</p>
                <x-guide-screenshot
                    image="transcript.png" :width="428" :height="491"
                    title="{{ __('Find your way around Watch') }}"
                    alt="{{ __('A saved Spanish to English transcript with search, Edit, Jump, and Copy controls on each line.') }}"
                    :steps="[
                        ['x' => 3, 'y' => 21, 'w' => 422, 'h' => 42, 'cx' => 408, 'cy' => 20, 'text' => __('Saved generation switches between completed tracks for this video.')],
                        ['x' => 3, 'y' => 146, 'w' => 422, 'h' => 42, 'cx' => 408, 'cy' => 146, 'text' => __('Search transcript helps you find the line you want to read or correct.')],
                        ['x' => 50, 'y' => 242, 'w' => 188, 'h' => 32, 'cx' => 252, 'cy' => 258, 'text' => __('Edit corrects a word, Jump seeks to the line, and Copy copies its text.')],
                    ]"
                />
            </section>

            <section id="replace-lyrics" aria-labelledby="lyrics-title">
                <p class="eyebrow">{{ __('04 / Make corrections') }}</p>
                <h2 id="lyrics-title">{{ __('Replace full lyrics') }}</h2>
                <p>{{ __('Use this when you have the complete lyrics for a song and want to replace its generated transcript. Start with a completed generation.') }}</p>
                <ol>
                    <li>{!! strtr(e(__('In :slot1:Watch:slot2:, select the track in :slot3:Saved generation:slot4:, then choose :slot5:Lyric correction:slot6:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Paste the complete lyrics for this exact version of the song, in order. Include every repeated verse and chorus. Remove section tags such as :slot1:, :slot2:, and :slot3:; paste only the words that are sung.')), [':slot1:' => '<code>[Verse]</code>', ':slot2:' => '<code>[Chorus]</code>', ':slot3:' => '<code>[Bridge]</code>']) !!}</li>
                    <li>{!! strtr(e(__('Keep the text within the displayed 25,000-character limit. Choose :slot1:Continue:slot2: and review the full-track replacement notice.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Replace entire track:slot2: to confirm. The compact status strip shows progress; choose :slot3:View progress:slot4: for the stages or :slot5:Cancel replacement:slot6: while it is running.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                    <li>{{ __('When replacement finishes, play several lines to check the wording and timing. Translations, enabled pronunciation readings, and word data are rebuilt with the corrected text.') }}</li>
                </ol>
                <aside class="guide-note">{!! strtr(e(__(':slot1:This replaces the entire track.:slot2: It fits your lyrics to existing timing; it does not fetch lyrics, merge a partial verse, or create new timing from the audio. Keep a copy of text you need to preserve: a completed replacement has no undo.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</aside>
                <p>{!! strtr(e(__('Full lyrics correction uses Transcriber, regardless of the generation’s model, and does not use additional generated-video minutes. Incomplete or wrong lyrics can put words at the wrong times. If needed, use :slot1:Generate again:slot2: to create fresh subtitles; regeneration can use plan minutes.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <x-guide-screenshot
                    image="replace-lyrics.png" :width="428" :height="488"
                    title="{{ __('Review the full replacement') }}"
                    alt="{{ __('Full lyrics replacement form with example Spanish lyrics and the final Replace entire track confirmation.') }}"
                    :steps="[
                        ['x' => 12, 'y' => 224, 'w' => 404, 'h' => 152, 'cx' => 400, 'cy' => 224, 'text' => __('Paste the complete lyrics in order, including repeated verses. Choose Continue below the text.')],
                        ['x' => 12, 'y' => 378, 'w' => 404, 'h' => 99, 'cx' => 400, 'cy' => 378, 'text' => __('After Continue, this notice appears. Check it before selecting Replace entire track; a completed replacement has no undo.')],
                    ]"
                />
            </section>

            <section id="fix-a-word" aria-labelledby="word-fix-title">
                <p class="eyebrow">{{ __('05 / Make corrections') }}</p>
                <h2 id="word-fix-title">{{ __('Fix a single word') }}</h2>
                <p>{{ __('For a small mistake, edit one word in a completed transcript instead of replacing the full lyrics.') }}</p>
                <ol>
                    <li>{!! strtr(e(__('Find the line in :slot1:Watch:slot2:, using transcript search if needed.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Select :slot1:Edit:slot2: on that line, then select the word you want to correct.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Enter the replacement in :slot1:Correct word:slot2: and choose :slot3:Save correction:slot4:. Use :slot5:Cancel:slot6: to discard an unsaved edit.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('Wait for the update, then check the line. Select :slot1:Done:slot2: to leave word selection.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ol>
                <p>{{ __('The correction keeps the line’s timing and refreshes its translation, enabled pronunciation readings, and word cards. It does not use additional generated-video minutes. Wait for generation or a lyrics replacement to finish before making another correction.') }}</p>
                <x-guide-screenshot
                    image="fix-a-word.png" :width="428" :height="283"
                    title="{{ __('Correct just one word') }}"
                    alt="{{ __('Single-word editor correcting the example transcript word una to luna while keeping the surrounding line.') }}"
                    :steps="[
                        ['x' => 118, 'y' => 6, 'w' => 41, 'h' => 27, 'cx' => 139, 'cy' => -5, 'text' => __('Select Edit on a line, then select the mistaken word. Here, una should be luna.')],
                        ['x' => 60, 'y' => 95, 'w' => 359, 'h' => 42, 'cx' => 42, 'cy' => 116, 'text' => __('Type the corrected word in Correct word, keeping any punctuation you need.')],
                        ['x' => 60, 'y' => 197, 'w' => 119, 'h' => 32, 'cx' => 42, 'cy' => 213, 'text' => __('Save correction updates the line and its word data. Review the result, then select Done.')],
                    ]"
                />
            </section>

            <section id="word-cards" aria-labelledby="cards-title">
                <p class="eyebrow">{{ __('06 / Learn in context') }}</p>
                <h2 id="cards-title">{{ __('Explore word cards') }}</h2>
                <ol>
                    <li>{{ __('Play a completed track and move over a word in the subtitles on the video to preview the available word information.') }}</li>
                    <li>{{ __('Click the word to pin its detail card. New details may take a moment to load the first time you request them.') }}</li>
                    <li>{{ __('Read the meaning, pronunciation, and usage information available for that word in its sentence. Click another word to explore it.') }}</li>
                </ol>
                <p>{!! strtr(e(__('In :slot1:Study → Active recall:slot2:, enable :slot3:Pause video on word hover:slot4: if you want time to read. Word cards explain the current video’s words; saved vocabulary and spaced-repetition review are not currently available.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</p>
                {{-- Word-card screenshots or video go here. --}}
            </section>

            <section id="study-tools" aria-labelledby="study-title">
                <p class="eyebrow">{{ __('07 / Study') }}</p>
                <h2 id="study-title">{{ __('Display & study tools') }}</h2>
                <h3>{{ __('Place and style the subtitles') }}</h3>
                <p>{!! strtr(e(__('Open :slot1:Study → Caption display:slot2:. Use :slot3:Show overlay:slot4: to show or hide the subtitles on the video. Choose :slot5:Bottom:slot6:, :slot7:Top:slot8:, or :slot9:Compact:slot10: under Position, then adjust :slot11:Caption size:slot12:, :slot13:Density:slot14:, and :slot15:Contrast:slot16:. :slot17:Word gloss:slot18: controls available short word meanings.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>', ':slot7:' => '<strong>', ':slot8:' => '</strong>', ':slot9:' => '<strong>', ':slot10:' => '</strong>', ':slot11:' => '<strong>', ':slot12:' => '</strong>', ':slot13:' => '<strong>', ':slot14:' => '</strong>', ':slot15:' => '<strong>', ':slot16:' => '</strong>', ':slot17:' => '<strong>', ':slot18:' => '</strong>']) !!}</p>
                <p>{!! strtr(e(__('To move subtitles freely, uncheck :slot1:Attach subtitles to video:slot2: and drag the :slot3:Move subtitles:slot4: handle. You can also focus the handle and use the arrow keys; hold Shift for larger steps. Check attachment again to return to the selected position. The free position lasts while the overlay is open.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</p>

                <h3>{{ __('Adjust timing') }}</h3>
                <p>{!! strtr(e(__('If the subtitles appear too early or late, adjust :slot1:Timing:slot2: with the slider or seconds field. The range is −10 to +10 seconds in 0.1-second steps. Use :slot3:Reset:slot4: to return to zero. This changes playback display timing, not the words or the stored cue timings.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</p>
                <x-guide-screenshot
                    image="study-display.png" :width="418" :height="459"
                    title="{{ __('Place captions and adjust timing') }}"
                    alt="{{ __('Study caption display settings, including attachment, position, size, density, contrast, and the timing adjustment.') }}"
                    :steps="[
                        ['x' => 11, 'y' => 76, 'w' => 396, 'h' => 44, 'cx' => 349, 'cy' => 78, 'text' => __('Uncheck Attach subtitles to video to move them freely. Check it again to return to your chosen position.')],
                        ['x' => 266, 'y' => 120, 'w' => 140, 'h' => 185, 'cx' => 254, 'cy' => 133, 'text' => __('Choose a position, then adjust the size, spacing, and contrast to suit the video.')],
                        ['x' => 11, 'y' => 398, 'w' => 396, 'h' => 49, 'cx' => 351, 'cy' => 397, 'text' => __('Use Timing to shift subtitle playback. Reset returns the offset to zero.')],
                    ]"
                />

                <h3>{{ __('Practice listening before reading') }}</h3>
                <p>{!! strtr(e(__('Under :slot1:Active recall:slot2:, turn on :slot3:Blur source words:slot4:, :slot5:Blur romanization:slot6:, or :slot7:Blur translation:slot8:. Hover or focus the part you want to reveal when you need a hint. Source-word reveals include that word’s reading when available; the full reading and translation can be revealed separately.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<strong>', ':slot6:' => '</strong>', ':slot7:' => '<strong>', ':slot8:' => '</strong>']) !!}</p>
                <p>{!! strtr(e(__('Enable :slot1:Pause video on word hover:slot2: to pause while inspecting a word. These are display preferences; they do not start another generation.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <x-guide-screenshot
                    image="study-recall.png" :width="418" :height="212"
                    title="{{ __('Listen first, reveal when ready') }}"
                    alt="{{ __('Active recall controls for blurring source words, romanization, and translation, plus pause on word hover.') }}"
                    :steps="[
                        ['x' => 360, 'y' => 40, 'w' => 48, 'h' => 115, 'cx' => 346, 'cy' => 51, 'text' => __('Turn on a blur option to hide that layer. Hover or focus it when you want a hint.')],
                        ['x' => 360, 'y' => 164, 'w' => 48, 'h' => 31, 'cx' => 346, 'cy' => 179, 'text' => __('Pause on word hover gives you time to read a word card while the video waits.')],
                    ]"
                />

                <h3>{{ __('Reset this device') }}</h3>
                <p>{!! strtr(e(__(':slot1:Study → This device → Clear local state:slot2: resets local settings and remembered tracks and signs the extension out. It does not delete your account’s server-side jobs or cancel your subscription. Sign in again to access retained generations.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
            </section>

            <section id="keyboard-shortcuts" aria-labelledby="shortcuts-title">
                <p class="eyebrow">{{ __('08 / Keep watching') }}</p>
                <h2 id="shortcuts-title">{{ __('Keyboard shortcuts') }}</h2>
                <p>{!! strtr(e(__('Enable :slot1:Study → Keyboard shortcuts → Shortcuts enabled:slot2:. Use these while the YouTube page has focus. Shortcuts do not run while you are typing in an input or editing text. On Mac, Alt is the Option key.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <table class="guide-shortcuts">
                    <caption>{{ __('Shortcuts for the current video') }}</caption>
                    <thead><tr><th scope="col">{{ __('Shortcut') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
                    <tbody>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:R:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Replay the current line') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:←:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Jump to the previous line') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:→:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Jump to the next line') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:T:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Show or hide translation') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:B:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Blur or reveal source words') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:A:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Toggle pause on word hover') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:X:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Focus the side-panel transcript') }}</td></tr>
                        <tr><td>{!! strtr(e(__(':slot1:Alt:slot2: + :slot3:Shift:slot4: + :slot5:C:slot6:')), [':slot1:' => '<kbd>', ':slot2:' => '</kbd>', ':slot3:' => '<kbd>', ':slot4:' => '</kbd>', ':slot5:' => '<kbd>', ':slot6:' => '</kbd>']) !!}</td><td>{{ __('Copy the current line') }}</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="saved-generations" aria-labelledby="saved-title">
                <p class="eyebrow">{{ __('09 / Come back to a video') }}</p>
                <h2 id="saved-title">{{ __('Saved generations & history') }}</h2>
                <p>{!! strtr(e(__('Use :slot1:Saved generation:slot2: at the top of the transcript to switch between completed tracks for the current video. The language pair and model help identify each one. Switching updates the transcript and video subtitles without generating again.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <ul>
                    <li>{!! strtr(e(__(':slot1:Refresh saved generations:slot2: checks for the latest available tracks. Switching and deletion wait while generation or a lyrics change is active.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Delete selected generation:slot2: in the dropdown removes that generation and its stored track after confirmation. It switches to another available track, or returns to setup if none remain.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('The :slot1:History:slot2: tab lists your latest 25 jobs, grouped into Videos and Shorts. Use :slot3:Open video:slot4: to return to YouTube. Available completed generations are recovered for that video.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</li>
                    <li>{{ __('For a failed job, open the video, review Watch settings, and choose Generate. The failed job’s options are not automatically restored.') }}</li>
                    <li>{!! strtr(e(__('Use :slot1:Cancel generation:slot2: on a queued or running job when you want to stop it. Read the minute-use notice before confirming.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ul>
                <p>{{ __('Completed tracks are retained for 30 days. Reopening a retained track uses no new generation minutes. Deleting a completed track does not refund used minutes; creating a new generation can use minutes again.') }}</p>
                <p>{{ __('You can also delete individual jobs or clear all jobs from the website dashboard. If a deleted track is still visible on an already-open YouTube page, refresh the page.') }}</p>
            </section>

            <section id="account" aria-labelledby="account-title">
                <p class="eyebrow">{{ __('10 / Your account') }}</p>
                <h2 id="account-title">{{ __('Account & usage') }}</h2>
                <p>{!! strtr(e(__('The extension’s :slot1:Account:slot2: tab shows your sign-in status, plan, used and reserved minutes, remaining balance, and reset date. Reserved minutes belong to jobs that have not finished yet.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <p>{!! strtr(e(__('Open the :slot1:website dashboard:slot2: for billing, connected extension installs, and recent jobs. The website may need a separate sign-in; use the same account as the extension.')), [':slot1:' => '<a href="'.e(route('dashboard')).'">', ':slot2:' => '</a>']) !!}</p>
                <ul>
                    <li>{!! strtr(e(__(':slot1:Refresh status:slot2: updates the dashboard snapshot.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Manage billing:slot2: or :slot3:Manage or cancel subscription:slot4: opens the billing portal for payment and subscription changes.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__('A job’s :slot1:Support ID:slot2: opens its status, stages, timings, language pair, and available failure details. Include this ID when reporting a generation issue.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Sign out:slot2: disconnects the extension session. Signing out or uninstalling does not cancel your subscription.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Delete account:slot2: permanently removes account data and cancels an active subscription. Read the dashboard’s confirmation carefully before proceeding.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ul>
            </section>

            <section id="troubleshooting" aria-labelledby="help-title">
                <p class="eyebrow">{{ __('11 / Get help') }}</p>
                <h2 id="help-title">{{ __('Troubleshooting') }}</h2>
                <dl class="guide-help">
                    <div><dt>{{ __('The panel says “No video here”') }}</dt><dd>{{ __('Switch to a public YouTube video or Short in the same window. After installing or updating, refresh the page and reopen the panel.') }}</dd></div>
                    <div><dt>{{ __('I cannot start generation') }}</dt><dd>{{ __('Check Account for your sign-in, active plan, and remaining minutes. Wait for an available queue slot if your plan’s limit is reached, and follow any message shown in Watch.') }}</dd></div>
                    <div><dt>{{ __('I cannot see subtitles') }}</dt><dd>{{ __('Check Study → Show overlay, play a part of the video with speech, and confirm the selected saved generation. Use Refresh saved generations if needed.') }}</dd></div>
                    <div><dt>{{ __('The words or timing are wrong') }}</dt><dd>{!! strtr(e(__('Try a :slot1:single-word correction:slot2:, :slot3:full lyrics replacement:slot4:, or the :slot5:Timing control:slot6:. Check the result against the video. For fresh subtitles, use Generate again and review the minute-use notice.')), [':slot1:' => '<a href="#fix-a-word">', ':slot2:' => '</a>', ':slot3:' => '<a href="#replace-lyrics">', ':slot4:' => '</a>', ':slot5:' => '<a href="#study-tools">', ':slot6:' => '</a>']) !!}</dd></div>
                    <div><dt>{{ __('Something failed or looks broken') }}</dt><dd>{!! strtr(e(__('Open the job’s Support ID in your dashboard. Note the failure code, video, language pair, what you expected, and steps to reproduce the problem. :slot1:Contact support:slot2: with those details; avoid sending passwords or full transcripts.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</dd></div>
                </dl>
            </section>
            <a class="text-link strong-link" href="#guide-top">{!! strtr(e(__('Back to the top :slot1:↑:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
        </article>
    </div>
@endsection
