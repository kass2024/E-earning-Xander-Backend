# Daily.co integration

This application supports **Zoom** (default) and **Daily** as live meeting providers. Zoom remains fully supported; Daily is optional and feature-flagged.

## Environment variables

Add to backend `.env`:

```env
DAILY_INTEGRATION_ENABLED=true
DAILY_API_KEY=your_daily_api_key
DAILY_DOMAIN=your-subdomain.daily.co
DAILY_API_BASE_URL=https://api.daily.co/v1
```

Never expose `DAILY_API_KEY` in React or public responses.

## Daily dashboard setup

1. Create a Daily account at [daily.co](https://www.daily.co/)
2. **Developers → API keys** — create an API key → `DAILY_API_KEY`
3. Note your domain (e.g. `xandertech.daily.co`) → `DAILY_DOMAIN`

Webhooks are **not required**. The Daily dashboard does not expose a webhook UI on all plans; live classes, room creation, and join tokens work without them.

## Enable Daily

### Main platform (Xander Global Scholars courses)

1. Set `DAILY_INTEGRATION_ENABLED=true` and configure credentials
2. Run migrations: `php artisan migrate`
3. Admin → **Zoom Meetings** → **Main platform live meetings**
4. Set **Meeting platform** to **Daily** and save

### Partner institution

1. Admin → **Partner Institutions** → **Manage** → **Branding**
2. Set **Meeting platform** to **Daily**

Provider changes apply to **new** live sessions only. Existing Zoom sessions continue using Zoom.

## Architecture

| Layer | Location |
|-------|----------|
| Provider enum | `app/Enums/MeetingProvider.php` |
| Interface | `app/Contracts/MeetingProviderInterface.php` |
| Manager | `app/Services/Meetings/MeetingProviderManager.php` |
| Zoom wrapper | `app/Services/Meetings/ZoomMeetingProvider.php` |
| Daily API | `app/Services/Meetings/DailyApiService.php` |
| Daily provider | `app/Services/Meetings/DailyMeetingProvider.php` |
| React launcher | `src/components/live/LiveMeetingExperience.tsx` |
| Daily UI | `src/components/live/DailyMeetingRoom.tsx` |

## Cloud recording

1. Set `DAILY_RECORDING_ENABLED=true` in backend `.env`
2. In the Daily dashboard, enable **Cloud recordings** for your domain/plan
3. Host Record button uses:
   - Daily JS `startRecording()` / `stopRecording()` in the call
   - REST `POST /rooms/{name}/recordings/start|stop` via cohort/live-class APIs

Cohort: `POST /api/livezoom-cohort/{id}/recording` `{ "action": "start"|"stop" }`  
Live class: `POST /api/instructor/live-classes/{material}/recording`

Recordings appear in the Daily dashboard **Recordings** tab (and via webhooks if configured).

## Testing

```bash
php artisan test --filter=MeetingProvider
npm run build
```

Regression: schedule a class on a **Zoom** institution and join as instructor + learner.

## Rollback

1. Set `DAILY_INTEGRATION_ENABLED=false`
2. Set affected institutions back to **Zoom** in admin
3. Deploy previous build if needed

Existing Zoom data and meetings are not modified by this integration.
