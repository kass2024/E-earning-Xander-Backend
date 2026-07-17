<?php



namespace App\Http\Controllers\Api;



use App\Enums\MeetingProvider;

use App\Http\Controllers\Controller;

use App\Models\User;

use App\Services\Meetings\MeetingProviderManager;

use App\Services\Meetings\MeetingProviderStatusService;

use App\Services\PlatformSettingsService;

use App\Support\PlatformInstitutionHelper;

use Illuminate\Http\Request;



class PlatformMeetingSettingsController extends Controller

{

    public function show(

        Request $request,

        PlatformSettingsService $settings,

        MeetingProviderStatusService $status,

    ) {

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);



        return response()->json([

            'main_platform_meeting_provider' => $settings->mainPlatformMeetingProvider()->value,

            'meeting_provider_status' => $status->summary(),

            'can_manage_main_platform_settings' => PlatformInstitutionHelper::canManageMainPlatformMeetingSettings($actor),

        ]);

    }



    public function update(

        Request $request,

        PlatformSettingsService $settings,

        MeetingProviderManager $providers,

        MeetingProviderStatusService $status,

    ) {

        $user = PlatformInstitutionHelper::resolveActorFromRequest($request);

        if (!$user || !PlatformInstitutionHelper::canManageMainPlatformMeetingSettings($user)) {

            return response()->json([

                'message' => $this->deniedMessage($user),

                'error_code' => 'main_platform_settings_forbidden',

            ], 403);

        }



        $data = $request->validate([

            'main_platform_meeting_provider' => 'required|string|in:zoom,daily',

        ]);



        $provider = MeetingProvider::fromStringOrDefault($data['main_platform_meeting_provider']);



        if (!(bool) config('daily.enabled', config('services.daily.integration_enabled', false))

            && $provider === MeetingProvider::Daily) {

            return response()->json([

                'message' => 'Daily integration is disabled. Set DAILY_INTEGRATION_ENABLED=true on the server.',

            ], 422);

        }



        try {

            $providers->assertSelectable($provider);

        } catch (\InvalidArgumentException $e) {

            return response()->json(['message' => $e->getMessage()], 422);

        }



        if (!$settings->isReady()) {
            return response()->json([
                'message' => 'Platform settings storage is not ready. Run php artisan migrate on the server.',
                'error_code' => 'platform_settings_table_missing',
            ], 503);
        }

        $settings->setMainPlatformMeetingProvider($provider);



        return response()->json([

            'message' => 'Main platform meeting provider updated.',

            'main_platform_meeting_provider' => $provider->value,

            'meeting_provider_status' => $status->summary(),

            'can_manage_main_platform_settings' => true,

        ]);

    }



    protected function deniedMessage(?User $user): string

    {

        if (!$user) {

            return 'Sign in again, then retry. Your session email could not be verified.';

        }



        if (!PlatformInstitutionHelper::hasAdminAccess($user)) {

            return 'Only administrators can change the main platform meeting provider.';

        }



        if (!empty($user->platform_institution_id)) {

            return 'Partner institution accounts cannot change the live meeting provider. Ask a main platform admin to update Settings → Live meetings.';

        }



        return 'Your account does not have permission to change the main platform meeting provider.';

    }

}


