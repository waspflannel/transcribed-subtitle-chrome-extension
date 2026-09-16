export const overlayStyles = String.raw`        :host {
          all: initial;
          --accent: #d83b3b;
          --accent-bright: #e85d5d;
          --accent-soft: #ec9b9b;
          --hairline: rgba(255, 255, 255, 0.12);
          bottom: calc(82px + env(safe-area-inset-bottom));
          left: max(16px, env(safe-area-inset-left));
          pointer-events: none;
          position: fixed;
          right: max(16px, env(safe-area-inset-right));
          top: auto;
          width: auto;
          z-index: 2147483647;
        }

        :host([data-position="top"]) {
          bottom: auto;
          top: 72px;
        }

        :host([data-position="compact"]) {
          left: auto;
          right: max(16px, env(safe-area-inset-right));
          width: min(430px, calc(100vw - 32px));
        }

        :host([data-floating="true"]) .rail {
          box-sizing: border-box;
          max-width: none;
          width: 100%;
        }

        .drag-handle {
          background: #161616;
          border: 1px solid var(--hairline);
          bottom: 100%;
          color: #f1f1f1;
          cursor: grab;
          font: 12px/1.2 'Geist Sans', ui-sans-serif, system-ui, sans-serif;
          height: 28px;
          padding: 4px 10px;
          pointer-events: auto;
          position: absolute;
          right: 0;
          touch-action: none;
          user-select: none;
        }

        .drag-handle[hidden] { display: none; }
        .drag-handle[data-dragging] { cursor: grabbing; }
        .drag-handle:focus-visible { outline: 2px solid var(--accent-bright); }

        .rail {
          background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.015)),
            rgba(8, 8, 8, 0.88);
          backdrop-filter: blur(20px) saturate(130%);
          border: 1px solid rgba(255, 255, 255, 0.12);
          border-left: 2px solid var(--accent, #d83b3b);
          border-radius: 0;
          box-shadow: 0 18px 54px rgba(0, 0, 0, 0.5);
          color: #f1f1f1;
          display: grid;
          font-family: 'Geist Sans', "Geist", Inter, ui-sans-serif, system-ui, sans-serif;
          gap: 12px;
          grid-template-columns: minmax(112px, auto) minmax(0, 1fr) auto;
          line-height: 1.35;
          margin: 0 auto;
          max-width: min(860px, calc(100vw - 32px));
          min-height: auto;
          padding: 12px 16px;
          pointer-events: auto;
        }

        .rail--message {
          max-width: min(720px, calc(100vw - 32px));
        }

        .rail--generating {
          display: block;
          padding-block: 14px;
          text-align: center;
        }

        .rail--generating .title {
          animation: generating-pulse 1.4s ease-in-out infinite;
        }

        :host([data-position="compact"]) .rail {
          gap: 14px;
          grid-template-columns: minmax(0, 1fr);
          padding: 16px;
        }

        .rail-meta {
          align-content: start;
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
          min-width: 0;
        }

        .eyebrow {
          color: var(--accent-soft);
          font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
          font-size: 11px;
          font-weight: 600;
          letter-spacing: 0.14em;
          text-transform: uppercase;
          white-space: nowrap;
        }

        .cue-time {
          color: var(--accent-bright);
          font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.04em;
          white-space: nowrap;
        }

        .rail-main {
          align-content: center;
          display: grid;
          gap: 8px;
          min-width: 0;
        }

        .rail-main--message {
          gap: 6px;
        }

        .rail-controls {
          align-content: start;
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          justify-content: flex-end;
          min-width: 0;
        }

        .study-control {
          background: rgba(255, 255, 255, 0.05);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 0;
          color: #d1d5db;
          cursor: pointer;
          font: inherit;
          font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
          font-size: 10px;
          font-weight: 500;
          letter-spacing: 0.1em;
          min-height: 28px;
          padding: 0 10px;
          text-transform: uppercase;
          transition:
            background 120ms ease,
            border-color 120ms ease,
            box-shadow 120ms ease,
            color 120ms ease;
          white-space: nowrap;
        }

        .study-control:hover {
          background: rgba(216, 59, 59, 0.16);
          border-color: rgba(216, 59, 59, 0.55);
          color: #fff;
        }

        .study-control:focus-visible {
          box-shadow: 0 0 0 3px rgba(216, 59, 59, 0.34);
          outline: none;
        }

        .control-status {
          color: #ec9b9b;
          font-size: 11px;
          font-weight: 850;
          line-height: 1;
          white-space: nowrap;
        }

        .control-status.failed,
        .control-status.error {
          color: #fca5a5;
        }

        .control-status.info {
          color: #d1d5db;
        }

        .control-status.copied,
        .control-status.success {
          color: #5ec99a;
        }

        .title {
          color: #f1f1f1;
          font-size: 15px;
          font-weight: 800;
        }

        @keyframes generating-pulse {
          0%,
          100% {
            opacity: 1;
          }
          50% {
            opacity: 0.5;
          }
        }

        .detail {
          color: #d1d5db;
          font-size: 13px;
        }

        .meta {
          color: #9ca3af;
          display: flex;
          flex-wrap: wrap;
          font-size: 12px;
          gap: 8px;
        }

        .token-area {
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          justify-content: center;
          min-width: 0;
        }

        .translation {
          color: #f5f5f5;
          font-size: 17px;
          font-weight: 600;
          line-height: 1.25;
          overflow-wrap: anywhere;
        }

        .cue-romanization {
          color: var(--accent-soft);
          font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
          font-size: 12px;
          font-weight: 400;
          letter-spacing: 0.02em;
        }

        .study-blur {
          filter: blur(6px);
          opacity: 0.78;
          transition:
            filter 120ms ease,
            opacity 120ms ease;
          user-select: none;
        }

        .partial-source-layer:hover,
        .partial-source-layer:focus-visible {
          filter: blur(0);
          opacity: 1;
          user-select: text;
        }

        .token-card:hover .study-blur--token,
        .token-card:focus-visible .study-blur--token,
        .token-card[aria-pressed="true"] .study-blur--token,
        .study-cue-romanization:hover,
        .study-cue-romanization:focus-visible,
        .study-translation:hover,
        .study-translation:focus-visible {
          filter: blur(0);
          opacity: 1;
          user-select: text;
        }

        .token-slot {
          display: inline-grid;
          max-width: 100%;
          position: relative;
        }

        .token-card {
          align-items: center;
          background: rgba(255, 255, 255, 0.04);
          border: 1px solid rgba(255, 255, 255, 0.1);
          border-radius: 0;
          color: inherit;
          cursor: pointer;
          display: inline-flex;
          flex-direction: column;
          font: inherit;
          gap: 4px;
          justify-content: center;
          line-height: 1;
          max-width: 100%;
          min-height: 42px;
          min-width: 0;
          padding: 7px 12px;
          position: relative;
          text-align: center;
          transition:
            background 120ms ease,
            border-color 120ms ease,
            box-shadow 120ms ease;
        }

        .token-card:hover {
          background: rgba(216, 59, 59, 0.1);
          border-color: rgba(216, 59, 59, 0.45);
        }

        .token-card:focus-visible {
          box-shadow: 0 0 0 2px rgba(216, 59, 59, 0.5);
          outline: none;
        }

        .token-card[aria-pressed="true"] {
          background: rgba(216, 59, 59, 0.16);
          border-color: var(--accent);
          box-shadow: inset 0 -2px 0 0 var(--accent);
        }

        .token-text {
          color: #f5f5f5;
          font-size: 22px;
          font-weight: 600;
          line-height: 1.05;
          overflow-wrap: normal;
          word-break: keep-all;
        }

        .token-extra {
          color: var(--accent-soft);
          font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
          font-size: 11px;
          font-weight: 400;
          letter-spacing: 0.02em;
          line-height: 1.3;
          overflow-wrap: anywhere;
        }

        .token-inline-preview,
        .token-popover {
          background: rgba(8, 8, 8, 0.97);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 0;
          color: #e5e7eb;
          display: grid;
          font-size: 13px;
          gap: 8px;
          padding: 12px 14px;
          box-sizing: border-box;
          max-height: min(60vh, 420px);
          max-width: min(270px, calc(100vw - 24px));
          overflow-y: auto;
        }

        .token-inline-preview {
          bottom: calc(100% + 8px);
          box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3);
          display: none;
          left: 50%;
          min-width: 150px;
          position: absolute;
          transform: translateX(-50%);
          z-index: 1;
        }

        .token-card:hover .token-inline-preview,
        .token-card:focus-visible .token-inline-preview {
          display: grid;
        }

        .token-popover {
          border-top: 2px solid var(--accent);
          bottom: calc(100% + 14px);
          box-shadow: 0 18px 50px rgba(0, 0, 0, 0.5);
          left: 50%;
          position: absolute;
          transform: translate(calc(-50% + var(--popover-shift, 0px)), var(--popover-shift-y, 0px));
          width: min(270px, calc(100vw - 48px));
          z-index: 2;
        }

        :host([data-position="top"]) .token-popover {
          bottom: auto;
          top: calc(100% + 14px);
        }

        .token-popover::after {
          background: rgba(8, 8, 8, 0.97);
          border-bottom: 1px solid rgba(255, 255, 255, 0.14);
          border-right: 1px solid rgba(255, 255, 255, 0.14);
          bottom: -6px;
          content: "";
          height: 10px;
          left: 50%;
          position: absolute;
          transform: translateX(-50%) rotate(45deg);
          width: 10px;
        }

        .token-popover-header {
          align-items: center;
          display: flex;
          gap: 10px;
          justify-content: space-between;
        }

        .token-popover-title {
          color: #f5f5f5;
          font-size: 15px;
          font-weight: 600;
        }

        .icon-button {
          align-items: center;
          background: rgba(255, 255, 255, 0.06);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 0;
          color: #e5e7eb;
          cursor: pointer;
          display: inline-flex;
          font-size: 14px;
          height: 28px;
          justify-content: center;
          line-height: 1;
          padding: 0;
          width: 28px;
        }

        .icon-button:hover,
        .icon-button:focus-visible {
          background: rgba(216, 59, 59, 0.18);
          border-color: rgba(216, 59, 59, 0.55);
          box-shadow: none;
          color: #fff;
          outline: none;
        }

        .token-fields {
          display: grid;
          gap: 5px;
          grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }

        .field {
          display: grid;
          gap: 1px;
        }

        .field-label {
          color: #9ca3af;
          font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
          font-size: 10px;
          font-weight: 500;
          letter-spacing: 0.1em;
          text-transform: uppercase;
        }

        .field-value {
          color: #f1f1f1;
          font-size: 13px;
          line-height: 1.35;
          overflow-wrap: anywhere;
        }

        :host([data-position="compact"]) .rail-meta,
        :host([data-position="compact"]) .token-area,
        :host([data-position="compact"]) .rail-controls {
          justify-content: flex-start;
        }

        :host([data-position="compact"]) .token-card {
          min-height: 38px;
          padding: 6px 10px;
        }

        :host([data-position="compact"]) .token-text {
          font-size: 20px;
        }

        :host([data-position="compact"]) .translation {
          font-size: 16px;
        }

        :host([data-position="compact"]) .token-popover {
          bottom: auto;
          left: auto;
          margin-top: 8px;
          position: relative;
          transform: none;
          width: auto;
        }

        :host([data-position="compact"]) .token-popover::after {
          display: none;
        }

        :host([data-caption-size="small"]) .token-text {
          font-size: 19px;
        }

        :host([data-caption-size="small"]) .translation {
          font-size: 15px;
        }

        :host([data-caption-size="small"]) .cue-romanization,
        :host([data-caption-size="small"]) .token-extra {
          font-size: 11px;
        }

        :host([data-caption-size="large"]) .token-text {
          font-size: 26px;
        }

        :host([data-caption-size="large"]) .translation {
          font-size: 20px;
        }

        :host([data-caption-size="large"]) .cue-romanization,
        :host([data-caption-size="large"]) .token-extra {
          font-size: 14px;
        }

        :host([data-caption-density="compact"]) .rail {
          gap: 8px;
          padding: 9px 12px;
        }

        :host([data-caption-density="compact"]) .rail-main {
          gap: 5px;
        }

        :host([data-caption-density="compact"]) .token-card {
          min-height: 34px;
          padding: 5px 9px;
        }

        :host([data-caption-theme="high"]) .rail,
        :host([data-caption-theme="high"]) .token-popover,
        :host([data-caption-theme="high"]) .token-inline-preview {
          background: #000;
          border-color: #fff;
          box-shadow: 0 0 0 2px #000, 0 18px 54px rgba(0, 0, 0, 0.6);
          color: #fff;
        }

        :host([data-caption-theme="high"]) .token-card,
        :host([data-caption-theme="high"]) .study-control {
          background: #111;
          border-color: #fff;
          color: #fff;
        }

        :host([data-caption-theme="high"]) .token-text,
        :host([data-caption-theme="high"]) .translation {
          color: #fff;
        }

        :host([data-caption-theme="high"]) .cue-romanization,
        :host([data-caption-theme="high"]) .token-extra,
        :host([data-caption-theme="high"]) .eyebrow {
          color: #e85d5d;
        }

        @media (max-width: 899px) {
          :host {
            left: 16px;
            right: 16px;
          }

          .rail {
            grid-template-columns: minmax(0, 1fr);
            padding: 12px 14px;
          }

          .rail-controls {
            grid-column: 1 / -1;
            justify-content: flex-start;
          }

          .rail-main {
            grid-column: 1 / -1;
          }

          .translation {
            font-size: 16px;
          }

          .token-card {
            min-height: 40px;
          }

          .token-text {
            font-size: 21px;
          }
        }

        @media (max-width: 599px) {
          :host {
            bottom: 84px;
            left: 10px;
            right: 10px;
          }

          :host([data-position="top"]) {
            top: 64px;
          }

          .rail {
            gap: 8px;
            grid-template-columns: minmax(0, 1fr);
            padding: 10px 12px;
          }

          .rail-meta {
            gap: 6px;
          }

          .rail-controls {
            gap: 5px;
          }

          .study-control {
            font-size: 10px;
            min-height: 27px;
            padding: 0 7px;
          }

          .eyebrow,
          .cue-time {
            font-size: 12px;
          }

          .token-area {
            flex-wrap: nowrap;
            justify-content: flex-start;
            overflow-x: auto;
            padding-bottom: 2px;
          }

          .token-slot {
            flex: 0 0 auto;
          }

          .token-card {
            min-height: 38px;
            padding: 6px 10px;
          }

          .token-text {
            font-size: 19px;
          }

          .token-extra,
          .cue-romanization {
            font-size: 13px;
          }

          .translation {
            font-size: 15px;
          }

          .token-popover {
            bottom: auto;
            left: auto;
            margin-top: 8px;
            position: relative;
            transform: none;
            width: auto;
          }

          .token-popover::after {
            display: none;
          }
        }

        /* Hover previews fade without moving the synchronized caption rail. */
        @media (prefers-reduced-motion: no-preference) {
          @keyframes word-preview-enter {
            from { opacity: 0; }
          }

          .token-inline-preview {
            animation: word-preview-enter 140ms ease-out;
          }
        }

        @media (prefers-reduced-motion: reduce) {
          *,
          *::before,
          *::after {
            animation: none !important;
            transition: none !important;
            scroll-behavior: auto !important;
          }
        }`;
