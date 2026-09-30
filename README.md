# Transcribe

Transcribe is a Chrome extension that helps you learn languages while watching YouTube. It turns a video's audio into subtitles, adds optional translations, and lets you explore individual words as you watch.

It works with public YouTube videos and Shorts, including videos without existing captions.

## What you can do

- Generate subtitles that stay in sync with the video.
- Choose the spoken language or let the app detect it, then translate into your preferred language.
- Select words to see learning cards with meanings and pronunciation information when available.
- Show romanization to help read unfamiliar scripts.
- Replay lines, search the transcript, and hide or reveal text to practise listening.
- Correct subtitle mistakes or paste known lyrics to improve a track.
- Revisit saved subtitles and adjust their size and appearance.

## How it works

Once set up, open a YouTube video, choose your language options in the extension, and select **Generate**. Subtitles appear over the video, with a side panel for the transcript, word cards, and settings.

Transcribe runs with a backend on your computer or a private server. You supply your own ElevenLabs API key and an OpenAI or Cerebras API key in Settings. There is no Transcribe account or subscription; those providers charge separately for usage.

## Built with

- **Extension:** TypeScript, WXT, HTML, and CSS.
- **Backend:** PHP and Laravel.
- **Storage and background jobs:** PostgreSQL and Redis.
- **Speech transcription:** ElevenLabs Scribe.
- **Translations and language learning features:** OpenAI or Cerebras.
- **Audio processing:** yt-dlp and FFmpeg.

## More information

See the [setup and hosting guide](docs/operations/production-hosting-and-ops.md) for running your own instance, or the [architecture overview](ARCHITECTURE.md) for technical details.
