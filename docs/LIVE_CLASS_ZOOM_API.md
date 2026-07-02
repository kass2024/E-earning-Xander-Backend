# Live Class & Zoom Embed API

Base URL: `{API_BASE}/api` (e.g. `http://127.0.0.1:8000/api`)

All routes below are under the Laravel API prefix. Most require authentication or role-specific body fields.

---

## Frontend — Zoom Client View (default)

Docs: [Zoom Meeting SDK](https://marketplacefront.zoom.us/sdk/meeting/web/components/index.html)

**Default:** full-page Zoom Web Client via `ZoomMtg` (Client View). Meeting routes (`/meeting/room`, `/live-cohort/.../room`, `/live-cohort/.../host`) render full viewport — no dashboard sidebar, no iframe, no CSS scale/clipping.

| Step | Parrot implementation |
|------|----------------------|
| `ZoomMtg.preLoadWasm()` + `prepareWebSDK()` | `loadZoomClientSdk()` in `src/lib/zoomClientLoader.ts` |
| `ZoomMtg.init({ leaveUrl, patchJsMedia })` | `initClient()` in `src/lib/zoomClientSession.ts` |
| `ZoomMtg.join({ signature, meetingNumber, userName, passWord, zak? })` | `startZoomClientMeeting()` — host gets `zak` only when `isHost`; participants join without `zak` |
| Leave redirect | `/meeting-ended` (`leaveUrl`) |

**Component View (optional):** append `?view=component` to use embeddable custom UI (`EmbeddedZoomMeeting.tsx` + `ZoomMtgEmbedded.createClient()`). Use only when you need a custom embedded layout inside the app shell.

### Component View reference (opt-in only)

| SDK doc step | Parrot implementation |
|--------------|----------------------|
| Step 1 — `ZoomMtgEmbedded.createClient()` | `loadZoomEmbeddedModule()` → `createClient()` in `EmbeddedZoomMeeting.tsx` |
| Step 2 — HTML container `#meetingSDKElement` | `<div id="meetingSDKElement" ref={rootRef} class="zoom-sdk-mount" />` |
| Step 3 — join params | `buildZoomEmbeddedJoinOptions()` in `src/lib/zoomEmbeddedConfig.ts` |
| Step 4 — `init` + `join` | `buildZoomEmbeddedInitOptions()` then `client.join(...)` |

**Important:** Component View renders the **native Zoom toolbar and main panel inside `zoomAppRoot`**. Do not hide the SDK footer or overlay a custom toolbar — that breaks screen share, view options, and participant layout.

**Init (minimal, per docs):**

```ts
client.init({
  zoomAppRoot: root,
  language: "en-US",
  patchJsMedia: true,
});
```

**Join (per docs; `sdkKey` deprecated since SDK v4):**

```ts
client.join({
  signature,
  meetingNumber,
  password,
  userName,
  userEmail, // webinars
  zak,       // host start only
});
```

**Screen share:** requires `window.crossOriginIsolated === true` (COOP/COEP headers). See below.

---

## Configuration

### `GET /zoom/embed/config`

Returns whether the Zoom Meeting SDK (embedded) and Zoom REST API are configured.

**Response (200)**

```json
{
  "embed_enabled": true,
  "sdk_key": "YOUR_SDK_KEY",
  "sdk_key_preview": "AbCdEf…",
  "api_ready": true,
  "host_user_id": "zoom_host_user_id",
  "frontend_base": "http://localhost:8080",
  "platforms": ["web", "android"]
}
```

**Env vars (embed):** `ZOOM_EMBED_CLIENT_ID`, `ZOOM_EMBED_CLIENT_SECRET`  
**Env vars (REST):** `ZOOM_CLIENT_ID`, `ZOOM_CLIENT_SECRET`, `ZOOM_ACCOUNT_ID`, `ZOOM_HOST_USER_ID`

---

## Generic embed auth

### `POST /zoom/embed/auth`

Issue a Meeting SDK join payload for a material, raw meeting number, or webinar host.

**Body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `material_id` | integer | one of | Course material (live class) ID |
| `meeting_number` | string | one of | Raw Zoom meeting number |
| `webinar_host` | boolean | one of | Use configured webinar meeting |
| `role` | 0 \| 1 | no | `0` = participant, `1` = host (default `0`) |
| `user_name` | string | no | Display name in meeting |
| `password` | string | no | Meeting passcode hint |
| `instructor_email` | email | no | Host/instructor email |
| `user_email` | email | no | Joiner email |
| `student_id` | integer | no | Learner student ID |
| `platform_institution_id` | integer | no | Branding override |

**Response (200)** — host (`role: 1`)

```json
{
  "sdk": {
    "signature": "…",
    "sdk_key": "…",
    "meeting_number": "12345678901",
    "password": "pass",
    "password_candidates": ["pass", ""],
    "user_name": "Instructor Name",
    "role": 1,
    "zak": "…"
  },
  "host": { "name": "…", "email": "…", "avatar_url": "…" },
  "company": { "name": "…" },
  "use_institution_logo": false,
  "session_title": "…"
}
```

**Errors:** `422` validation, `403` not authorized, `404` not found

---

## Course live class (primary flow)

Frontend route: `/meeting/room?material_id={id}&role={0|1}&student_id={id}`

### Learner join — `POST /learner/live-classes/{material}/sdk-auth`

**Body**

| Field | Type | Required |
|-------|------|----------|
| `student_id` | integer | yes* |
| `learner_email` | email | alt to student_id |

\* Enrolled learner with an active session (`can_join`).

**Response (200)**

```json
{
  "sdk": { "signature": "…", "sdk_key": "…", "meeting_number": "…", "password": "…", "user_name": "…", "role": 0 },
  "material": { "id": 10, "title": "…", "course_title": "…", "recording_enabled": true },
  "participant": { "name": "…", "avatar_url": "…" },
  "host": { "name": "…", "avatar_url": "…" },
  "company": { "name": "…" },
  "preview": false
}
```

**Errors:** `403` not enrolled / class not live, `422` missing student

---

### Instructor host — `POST /instructor/live-classes/{material}/sdk-auth`

**Body**

| Field | Type | Required |
|-------|------|----------|
| `instructor_email` | email | yes |

**Response:** Same shape as learner auth with `role: 1` and `zak` when available.

---

### Instructor preview (no host role) — `POST /instructor/live-classes/{material}/preview-sdk-auth`

Same as host auth but joins as participant (`role: 0`, `preview: true`) for dry runs.

---

### Start live session — `POST /instructor/live-classes/{material}/start`

Marks the class as live so learners can join. Optionally enables cloud recording.

**Body**

| Field | Type | Required |
|-------|------|----------|
| `instructor_email` | email | yes |
| `enable_recording` | boolean | no |

**Response (200)**

```json
{
  "message": "Live session marked as started…",
  "recording_enabled": true,
  "recording_warning": null,
  "session": { "id": 10, "live_state": "live", "…": "…" }
}
```

---

### Lobby (checked-in learners) — `GET /instructor/live-classes/{material}/lobby`

**Query:** `instructor_email` (required)

**Response (200)**

```json
{
  "material_id": 10,
  "waiting": [
    { "student_id": 5, "display_name": "Jean U.", "email": "…", "checked_in_at": "…" }
  ],
  "auto_admit_enabled": false
}
```

---

### Dismiss lobby student — `POST /instructor/live-classes/{material}/lobby/dismiss`

**Body:** `instructor_email`, `student_id`

---

### Auto-admit toggle — `POST /instructor/live-classes/{material}/auto-admit`

**Body:** `instructor_email`, `enabled` (boolean)

---

### Cloud recording — `POST /instructor/live-classes/{material}/recording`

**Body:** `instructor_email`, `action` (`start` \| `stop` \| `pause`)

---

### List instructor live classes — `GET /instructor/live-classes`

**Query:** `instructor_email`

---

## Webinar / meeting registration host

### `POST /meeting-registrations/webinar/sdk-auth`

**Body:** `user_name`, `user_email`, `platform_institution_id`, `refresh_host_profile` (optional)

Hosts the configured webinar meeting (`webinar_settings.zoom_meeting_id`).

---

## Live Zoom Cohort (queue-based sessions)

Separate from course materials; uses `livezoom_cohort` table.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/livezoom-cohort` | List cohorts |
| POST | `/livezoom-cohort/{id}/start` | Start session |
| POST | `/livezoom-cohort/{id}/host/sdk-auth` | Host SDK auth |
| POST | `/livezoom-cohort/{id}/queue/sdk-auth` | Participant SDK auth |
| GET | `/livezoom-cohort/{id}/queue/status` | Queue position / can_join |
| POST | `/livezoom-cohort/{id}/queue/admit-next` | Admit next waiting |
| POST | `/livezoom-cohort/{id}/recording` | Toggle recording |

---

## Zoom REST (admin)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/zoom/meetings` | List account meetings |

---

## SDK payload fields (frontend)

The React app passes `sdk` to `@zoom/meetingsdk/embedded`:

| Field | Usage |
|-------|--------|
| `sdk_key` | `client.init` / `client.join` |
| `signature` | JWT from backend |
| `meeting_number` | Zoom meeting ID |
| `password` | Passcode (try `password_candidates` on failure) |
| `user_name` | Display name |
| `role` | `1` = host, `0` = attendee |
| `zak` | Host token (optional; same-account embed may omit) |

---

## Screen share requirements (browser)

Screen share decode requires **cross-origin isolation**:

- `Cross-Origin-Opener-Policy: same-origin`
- `Cross-Origin-Embedder-Policy: credentialless`

Verify in DevTools console:

```js
window.crossOriginIsolated // must be true
```

**Dev:** Vite sets these headers (`vite.config.ts`).  
**Production:** `public/.htaccess` on the frontend build.

If `crossOriginIsolated` is `false`, shared screen stays black while audio/video may still work.

---

## Typical instructor flow

1. `POST /instructor/live-classes/{material}/start` — open the class
2. Open `/meeting/room?material_id={id}&role=1` — uses `sdk-auth` internally
3. Optional: `GET …/lobby`, `POST …/auto-admit`, `POST …/recording`

## Typical learner flow

1. Wait until session is live (`can_join`)
2. Open `/meeting/room?material_id={id}&role=0&student_id={id}`
3. Frontend calls `POST /learner/live-classes/{material}/sdk-auth`
