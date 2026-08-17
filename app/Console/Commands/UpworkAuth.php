<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-time OAuth bootstrap for the Upwork API.
 *
 * Upwork issues only a key (client id) and secret up front; the refresh token
 * that `UpworkClient` needs can only be minted by a human approving the app in
 * a browser. Run without options to get the consent URL, then re-run with the
 * ?code= value Upwork redirects back with.
 */
class UpworkAuth extends Command
{
    protected $signature = 'upwork:auth {--code= : Authorization code from the callback redirect}';

    protected $description = 'Bootstrap Upwork OAuth: print the consent URL, then exchange the code for tokens';

    public function handle(): int
    {
        if (! config('variables.upworkClientId') || ! config('variables.upworkClientSecret')) {
            $this->error('Set UPWORK_CLIENT_ID and UPWORK_CLIENT_SECRET in .env first.');

            return self::FAILURE;
        }

        return $this->option('code')
            ? $this->exchange($this->option('code'))
            : $this->printConsentUrl();
    }

    private function printConsentUrl(): int
    {
        $url = config('variables.upworkAuthorizeUrl').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('variables.upworkClientId'),
            'redirect_uri' => config('variables.upworkRedirectUri'),
        ]);

        $this->info('1. Open this URL in a browser signed in to the Upwork account:');
        $this->line('');
        $this->line($url);
        $this->line('');
        $this->info('2. Approve, then copy the "code" query parameter from the redirect and run:');
        $this->line('   php artisan upwork:auth --code=THE_CODE');
        $this->line('');
        $this->comment('Redirect URI in use: '.config('variables.upworkRedirectUri'));
        $this->comment('It must match a callback registered on the API key exactly, or Upwork rejects the request.');

        return self::SUCCESS;
    }

    private function exchange(string $code): int
    {
        $res = Http::asForm()->timeout(30)->post(config('variables.upworkTokenUrl'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('variables.upworkClientId'),
            'client_secret' => config('variables.upworkClientSecret'),
            'redirect_uri' => config('variables.upworkRedirectUri'),
        ]);

        $access = data_get($res->json(), 'access_token');
        $refresh = data_get($res->json(), 'refresh_token');

        if (! $res->successful() || ! $access || ! $refresh) {
            $this->error('Token exchange failed (HTTP '.$res->status().'): '.$res->body());
            $this->line('Authorization codes are single-use and short-lived — re-run `upwork:auth` for a fresh one.');

            return self::FAILURE;
        }

        $this->info('Token exchange succeeded. Put these in .env, then run `php artisan config:clear`:');
        $this->line('');
        $this->line('UPWORK_ACCESS_TOKEN='.$access);
        $this->line('UPWORK_REFRESH_TOKEN='.$refresh);

        if ($tenant = $this->tenantId($access)) {
            $this->line('UPWORK_TENANT_ID='.$tenant);
        } else {
            $this->line('');
            $this->comment('Could not read an organization id — leave UPWORK_TENANT_ID empty.');
            $this->comment('It is optional; Upwork falls back to the default organization.');
        }

        return self::SUCCESS;
    }

    /** Best-effort lookup of the organization id used for the tenant header. */
    private function tenantId(string $access): ?string
    {
        try {
            $res = Http::timeout(30)->withToken($access)
                ->post(config('variables.upworkBase'), ['query' => 'query { organization { id } }']);

            return $res->successful() ? data_get($res->json(), 'data.organization.id') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
