# Transcribe

an extension I built that helps you learn languages while watching YouTube. It turns a video's audio into subtitles, adds optional translations, and lets you explore individual words as you watch.

It works with public YouTube videos and Shorts, including videos without existing captions.

## What you can do

- Generate subtitles that stay in sync with the video.
- Choose the spoken language or let the app detect it, then translate into your preferred language.
- Select words to see learning cards with meanings and pronunciation information when available.
- Show romanization to help read unfamiliar scripts.
- Replay lines, search the transcript, and hide or reveal text to practise listening.
- Correct subtitle mistakes or paste known lyrics to improve a track.
- Revisit saved subtitles and adjust their size and appearance.


## Built with

- **Extension:** TypeScript, WXT, HTML, and CSS.
- **Backend:** PHP and Laravel.
- **Storage and background jobs:** PostgreSQL and Redis.
- **Speech transcription:** ElevenLabs Scribe.
- **Translations and language learning features:** OpenAI or Cerebras. ( more coming soon )
- **Audio processing:** yt-dlp and FFmpeg.

## More information

https://waspflannel.vercel.app/articles/inside-a-progressive-ai-subtitle-pipeline/
