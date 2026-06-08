export const overlayStyles = String.raw`        :host {
          all: initial;
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

        .rail {
          background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.02)),
            rgba(10, 10, 10, 0.86);
          backdrop-filter: blur(20px) saturate(130%);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 10px;
          box-shadow: 0 18px 54px rgba(0, 0, 0, 0.42);
          color: #f1f1f1;
          display: grid;
          font-family: "Geist", Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
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
          color: #ec9b9b;
          font-family: "Geist", Inter, ui-sans-serif, system-ui, sans-serif;
          font-size: 13px;
          font-weight: 850;
          letter-spacing: 0;
          white-space: nowrap;
        }

        .cue-time {
          color: #e85d5d;
          font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
          font-size: 12px;
          font-weight: 750;
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
          background: rgba(255, 255, 255, 0.07);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 8px;
          color: #d1d5db;
          cursor: pointer;
          font: inherit;
          font-size: 11px;
          font-weight: 850;
          min-height: 28px;
          padding: 0 8px;
          transition:
            background 120ms ease,
            border-color 120ms ease,
            box-shadow 120ms ease,
            color 120ms ease,
            transform 120ms ease;
          white-space: nowrap;
        }

        .study-control:hover {
          background: rgba(255, 255, 255, 0.12);
          border-color: rgba(216, 59, 59, 0.32);
          transform: translateY(-1px);
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

        .transcript-panel {
          background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.02)),
            rgba(10, 10, 10, 0.94);
          backdrop-filter: blur(20px) saturate(130%);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 10px;
          bottom: 92px;
          box-shadow: 0 18px 54px rgba(0, 0, 0, 0.46);
          color: #f1f1f1;
          display: grid;
          font-family: "Geist", Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
          gap: 12px;
          grid-template-rows: auto auto minmax(0, 1fr) auto;
          line-height: 1.35;
          max-width: calc(100vw - 32px);
          min-height: 280px;
          padding: 14px;
          pointer-events: auto;
          position: fixed;
          right: 16px;
          top: 72px;
          width: min(430px, calc(100vw - 32px));
          z-index: 2147483647;
        }

        .transcript-header {
          align-items: start;
          display: grid;
          gap: 10px;
          grid-template-columns: minmax(0, 1fr) auto;
        }

        .transcript-title {
          color: #f1f1f1;
          font-size: 15px;
          font-weight: 900;
        }

        .transcript-summary {
          color: #d1d5db;
          font-size: 12px;
          margin-top: 2px;
        }

        .transcript-search {
          display: grid;
          gap: 5px;
        }

        .transcript-search label {
          color: #d1d5db;
          font-size: 12px;
          font-weight: 850;
        }

        .transcript-search input {
          background: rgba(0, 0, 0, 0.32);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 8px;
          color: #f1f1f1;
          font: inherit;
          font-size: 13px;
          min-height: 34px;
          padding: 0 10px;
        }

        .transcript-search input:focus-visible,
        .transcript-action:focus-visible {
          box-shadow: 0 0 0 3px rgba(216, 59, 59, 0.34);
          outline: none;
        }

        .transcript-list {
          display: grid;
          gap: 8px;
          min-height: 0;
          overflow-y: auto;
          padding-right: 2px;
          scrollbar-color: rgba(216, 59, 59, 0.58) rgba(255, 255, 255, 0.08);
          scrollbar-width: thin;
        }

        .transcript-list::-webkit-scrollbar {
          width: 10px;
        }

        .transcript-list::-webkit-scrollbar-track {
          background: rgba(255, 255, 255, 0.08);
          border-radius: 999px;
        }

        .transcript-list::-webkit-scrollbar-thumb {
          background: rgba(216, 59, 59, 0.58);
          border: 2px solid rgba(10, 10, 10, 0.94);
          border-radius: 999px;
        }

        .transcript-list::-webkit-scrollbar-thumb:hover {
          background: rgba(216, 59, 59, 0.72);
        }

        .transcript-cue {
          background: rgba(255, 255, 255, 0.055);
          border: 1px solid rgba(255, 255, 255, 0.11);
          border-radius: 8px;
          content-visibility: auto;
          contain-intrinsic-size: 152px;
          display: grid;
          gap: 8px;
          padding: 10px;
        }

        .transcript-cue[aria-current="true"] {
          background: rgba(216, 59, 59, 0.16);
          border-color: rgba(216, 59, 59, 0.42);
          box-shadow: inset 3px 0 0 #e85d5d;
        }

        .transcript-cue-header,
        .transcript-cue-actions {
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
          justify-content: space-between;
        }

        .transcript-cue-index,
        .transcript-cue-time {
          color: #e85d5d;
          font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
          font-size: 11px;
          font-weight: 850;
        }

        .transcript-cue-body {
          display: grid;
          gap: 5px;
        }

        .transcript-source {
          color: #f1f1f1;
          font-size: 14px;
          font-weight: 800;
          overflow-wrap: anywhere;
        }

        .transcript-romanization,
        .transcript-translation {
          color: #d1d5db;
          font-size: 12px;
          overflow-wrap: anywhere;
        }

        .transcript-romanization {
          color: #ec9b9b;
        }

        .transcript-action {
          background: rgba(255, 255, 255, 0.07);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 8px;
          color: #e5e7eb;
          cursor: pointer;
          font: inherit;
          font-size: 11px;
          font-weight: 850;
          min-height: 28px;
          padding: 0 8px;
        }

        .transcript-action:hover {
          background: rgba(255, 255, 255, 0.12);
          border-color: rgba(216, 59, 59, 0.32);
        }

        .transcript-status {
          color: #d1d5db;
          font-size: 12px;
          font-weight: 850;
          min-height: 16px;
        }

        .transcript-status.success {
          color: #5ec99a;
        }

        .transcript-status.error {
          color: #fca5a5;
        }

        .transcript-empty {
          color: #d1d5db;
          font-size: 13px;
          margin: 0;
        }

        .title {
          color: #f1f1f1;
          font-size: 15px;
          font-weight: 800;
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
          color: #ec9b9b;
          font-size: 13px;
          font-weight: 650;
        }

        .study-blur {
          filter: blur(6px);
          opacity: 0.78;
          transition:
            filter 120ms ease,
            opacity 120ms ease;
          user-select: none;
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
          background: rgba(255, 255, 255, 0.075);
          border: 1px solid rgba(255, 255, 255, 0.11);
          border-radius: 8px;
          color: inherit;
          cursor: pointer;
          display: inline-grid;
          font: inherit;
          gap: 3px;
          line-height: 1;
          min-height: 42px;
          min-width: 0;
          padding: 7px 12px;
          position: relative;
          text-align: center;
          transition:
            background 120ms ease,
            border-color 120ms ease,
            box-shadow 120ms ease,
            transform 120ms ease;
        }

        .token-card:hover {
          background: rgba(255, 255, 255, 0.11);
          border-color: rgba(216, 59, 59, 0.38);
          transform: translateY(-1px);
        }

        .token-card:focus-visible {
          box-shadow: 0 0 0 3px rgba(216, 59, 59, 0.34);
          outline: none;
        }

        .token-card[aria-pressed="true"] {
          background: rgba(216, 59, 59, 0.18);
          border-color: rgba(216, 59, 59, 0.78);
          box-shadow: inset 0 0 24px rgba(216, 59, 59, 0.08), 0 0 22px rgba(216, 59, 59, 0.12);
        }

        .token-text {
          color: #f1f1f1;
          font-size: 22px;
          font-weight: 750;
          line-height: 1;
          overflow-wrap: anywhere;
        }

        .token-extra {
          color: #ec9b9b;
          font-size: 11px;
          font-weight: 650;
          line-height: 1.3;
          overflow-wrap: anywhere;
        }

        .token-inline-preview,
        .token-popover {
          background: rgba(12, 12, 12, 0.96);
          border: 1px solid rgba(255, 255, 255, 0.14);
          border-radius: 10px;
          color: #e5e7eb;
          display: grid;
          font-size: 13px;
          gap: 8px;
          padding: 12px 14px;
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
          bottom: calc(100% + 14px);
          box-shadow: 0 18px 50px rgba(0, 0, 0, 0.46);
          left: 50%;
          position: absolute;
          transform: translateX(-50%);
          width: min(270px, calc(100vw - 48px));
          z-index: 2;
        }

        .token-popover::after {
          background: rgba(12, 12, 12, 0.96);
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
          color: #f1f1f1;
          font-size: 14px;
          font-weight: 850;
        }

        .icon-button {
          align-items: center;
          background: rgba(255, 255, 255, 0.08);
          border: 1px solid rgba(255, 255, 255, 0.16);
          border-radius: 7px;
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
          background: rgba(255, 255, 255, 0.14);
          box-shadow: 0 0 0 3px rgba(216, 59, 59, 0.22);
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
          font-size: 11px;
          font-weight: 800;
        }

        .field-value {
          color: #f1f1f1;
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
        :host([data-caption-theme="high"]) .transcript-panel,
        :host([data-caption-theme="high"]) .token-popover,
        :host([data-caption-theme="high"]) .token-inline-preview {
          background: #000;
          border-color: #fff;
          box-shadow: 0 0 0 2px #000, 0 18px 54px rgba(0, 0, 0, 0.6);
          color: #fff;
        }

        :host([data-caption-theme="high"]) .token-card,
        :host([data-caption-theme="high"]) .study-control,
        :host([data-caption-theme="high"]) .transcript-action,
        :host([data-caption-theme="high"]) .transcript-cue {
          background: #111;
          border-color: #fff;
          color: #fff;
        }

        :host([data-caption-theme="high"]) .token-text,
        :host([data-caption-theme="high"]) .translation,
        :host([data-caption-theme="high"]) .transcript-source,
        :host([data-caption-theme="high"]) .transcript-title {
          color: #fff;
        }

        :host([data-caption-theme="high"]) .cue-romanization,
        :host([data-caption-theme="high"]) .token-extra,
        :host([data-caption-theme="high"]) .transcript-romanization,
        :host([data-caption-theme="high"]) .eyebrow {
          color: #e85d5d;
        }

        :host([data-caption-theme="high"]) .transcript-list {
          scrollbar-color: #e85d5d #111;
        }

        :host([data-caption-theme="high"]) .transcript-list::-webkit-scrollbar-track {
          background: #111;
          border: 1px solid #fff;
        }

        :host([data-caption-theme="high"]) .transcript-list::-webkit-scrollbar-thumb {
          background: #e85d5d;
          border-color: #000;
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

          .transcript-panel {
            left: 16px;
            right: 16px;
            width: auto;
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

          .transcript-panel {
            bottom: 96px;
            left: 10px;
            min-height: 260px;
            padding: 12px;
            right: 10px;
            top: 64px;
            width: auto;
          }

          .transcript-cue-actions {
            justify-content: flex-start;
          }
        }`;
