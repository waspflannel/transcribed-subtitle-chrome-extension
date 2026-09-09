# Smoothening smoke tests

Current branch: `smoothening-fixes` in `C:/transcribed-subtitle-extension`. All smoothening worker changes and the lyrics merge are integrated here. Redundant local smoothening branch names have been removed; detached worker folders remain. Main and the lyrics branch are preserved. No remote changes were made.

Load a fresh extension build from this checkout and run the matching backend. Each row is a user check, not a claimed browser pass. Use disposable records for cancellation/deletion/reset. Rows marked **controlled** require prepared local jobs or delayed/failing responses; normal clicking alone cannot prove those race conditions.

User smoke failures reopened G1, G5 and R7. See the [follow-up fixes, retest steps and remaining Stripe setup](smoothening-smoke-followup-review.md) before retesting those rows.

| ID | What changed / scope | Quick check and expected result |
| --- | --- | --- |
| G1 | Explicit generation cancellation | Cancel a queued job and a running job from Watch/History. Both stay Cancelled; no track reappears and the next waiting job can start. |
| G2 | Honest History navigation | Open a failed job from History. Its video opens with instructions to review Watch settings; no generation starts automatically. |
| G3 | Explicit dashboard refresh | Refresh a job after its status changes. The dashboard shows the current status and clear refresh wording. |
| G4 | Clear deletion identity and scope | Delete a disposable video's jobs/tracks. Confirmation identifies the video/count; unrelated videos remain. |
| G5 | Account/billing directions; recovery and verification changes excluded | Open Account/billing and select a preference your account cannot use. Directions explain the required access; URLs contain no credentials. Password recovery is outside this delivery. |
| R1 | Reliable panel startup | Open the panel on YouTube and an unsupported page. It loads without crashing and gives the appropriate state. |
| R2 | New generation cannot be replaced by old history | Generate for a video with older results. The new job stays selected as history refreshes. **Controlled:** delay old history until after Generate. |
| R3 | Recover generation after interruption | During generation, briefly disconnect, reconnect, then reopen the panel. The same job resumes with partial cues preserved and no second submission. |
| R4 | YouTube navigation without page reload | Go from home/search into a video and Shorts. Supported playback activates captions without requiring Reload. |
| R5 | Overlay survives player changes | With captions loaded, enter/exit fullscreen and navigate between videos. Captions attach to the current player with no duplicate rail. |
| R6 | Old video responses cannot overwrite the new video | Navigate A to B while A is loading. B keeps its own captions. **Controlled:** release delayed A success/failure after B loads. |
| R7 | Transcript controls target the displayed tab | Open the same video in two windows at different times. Seek from one panel. Only that panel's displayed tab moves. |
| R8 | Account isolation | Sign out of A and into B while requests are pending. B never shows A's history or track. **Controlled:** release A's delayed responses after B signs in. |
| R9 | Settings survive overlap; one submission | Change several settings quickly, then double-click Generate. Latest settings persist and only one job is submitted. |
| R10 | Transcript and word-card updates stay current | Quick-fix a cue and open two word cards. New cue text appears and both card results remain available. |
| R11 | Paused cue highlighting and seeking | Pause mid-cue, reopen the panel, then use Jump/Replay/Previous/Next. The matching cue highlights immediately. |
| R12 | Account action feedback survives polling | Sign in/out while the panel refreshes; also try invalid credentials. Busy state lasts until completion and the result/error remains visible. |
| R13 | Study focus and pause ownership | Hover then keyboard-focus a word, leave hover, then leave focus. Playback resumes only when appropriate. Repeat while manually paused: it stays paused. |
| R14 | Partial layers and local attachment recovery | Reveal available partial caption layers by mouse/keyboard. **Controlled:** fail local attachment, then Retry attachment; the existing track loads without another generation. |
| R15 | Full local reset | Clear local state with a track loaded in multiple tabs. Login, settings and tracks clear everywhere and do not immediately return. Do this last. |
| R16 | One credit settlement across terminal races | **Controlled:** overlap completion/failure/deletion for a disposable job. Reservation settles once; a replacement run's balance is unaffected. |
| R17 | Old pipeline work cannot adopt a retry | **Controlled:** retry a failed job, then release its previous run's delayed results. New artifacts, track and charges remain unchanged. |
| R18 | Queue publication failure settles cleanly | **Controlled:** fail queue dispatch. Job reports queue_publication_failed, reservation releases and capacity frees. Restore dispatch and retry successfully. |
| R19 | Duplicate prevention, FIFO and fresh heartbeat protection | **Controlled:** submit duplicates, queue jobs and retry an older row. One compatible run exists; jobs run by new submission order. A fresh heartbeat prevents stale timeout. |
| R20 | Active work remains visible in history | **Controlled:** prepare old/version-old active jobs. They remain visible in History/dashboard; incompatible completed tracks are not reused. |
| R21 | Timeout includes response body | **Controlled:** let headers arrive but stall the body. The panel eventually reports a useful timeout instead of staying busy forever. |
| R22 | Delay and visible-player selection | Apply positive subtitle delay near zero: no cue appears early. With multiple players, captions follow the visible player. |
| R23 | Editing shortcuts and card bounds | Type in editable fields: caption shortcuts must not fire. Open a long word card in a small/zoomed window: its close control stays reachable. |
| R24 | Password-reset session invalidation — excluded | No fix delivered or acceptance claimed. A separate security check would sign in on two browsers, reset the password and check the old session's authorization. |
| U1 | Queued means waiting | Submit a job while the running slot is full. It says queued/waiting, with no running stage until admitted. |
| U2 | Distinct estimated, reserved, charged and released minutes | Inspect support details before/during/after a job and after cancellation. Values are separate; unknown duration is not presented as a known bill. |
| U3 | Keep history during refresh failure | Load History, disconnect and refresh. Cards remain with an error/stale notice. Switch accounts: previous cards disappear. |
| U4 | Connection expiry; password guidance excluded | **Controlled:** prepare newer expired and older valid connections. Valid connections remain listed; expired ones do not. Password rules/hints are outside this delivery. |
| U5 | Readable partial Watch transcript | During generation, search, scroll and Copy partial text. Reading position stays stable; unsupported editing/word-card/Jump controls are absent until applicable. |

Extra regression: use Quick fix and full lyrics replacement, including partial-lyrics confirmation and cancellation. Updated text must appear, and lyrics cancellation must remain separate from generation cancellation.

Record failures as: **ID — steps — actual result — expected result**. See [the detailed checklist](smoothening-manual-checklist.md) for deeper acceptance cases and [the review](smoothening-review-2026-09-08.md) for automated validation evidence.
