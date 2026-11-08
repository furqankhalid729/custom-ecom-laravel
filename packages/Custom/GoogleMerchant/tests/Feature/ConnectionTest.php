<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Services\SetupChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    config(['google_merchant.client_id' => 'client-1', 'google_merchant.client_secret' => 'secret-1']);

    GmcAccount::query()->delete();
});

it('shows the connection page with the setup checklist', function () {
    $this->loginAsAdmin();

    get(route('admin.google_merchant.connection.index'))
        ->assertOk()
        ->assertSeeText(trans('google_merchant::app.connection.title'))
        ->assertSeeText(trans('google_merchant::app.checklist.public_https'));
});

it('redirects to google with a session state', function () {
    $this->loginAsAdmin();

    $response = post(route('admin.google_merchant.oauth.start'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query['state'])->toBe(session('gmc_oauth_state'))
        ->and(strlen($query['state']))->toBe(40)
        ->and($query['redirect_uri'])->toBe(route('admin.google_merchant.oauth.callback'));
});

it('rejects a callback with the wrong state', function () {
    $this->loginAsAdmin();

    session(['gmc_oauth_state' => str_repeat('a', 40)]);

    get(route('admin.google_merchant.oauth.callback', ['state' => 'bad', 'code' => 'x']))
        ->assertRedirect(route('admin.google_merchant.connection.index'))
        ->assertSessionHas('error');

    expect(GmcAccount::count())->toBe(0);

    Http::assertNothingSent();
});

it('stores encrypted tokens on a valid callback', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(fixture('token'))]);

    $this->loginAsAdmin();

    session(['gmc_oauth_state' => $state = str_repeat('s', 40)]);

    get(route('admin.google_merchant.oauth.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertRedirect(route('admin.google_merchant.connection.index'));

    $account = GmcAccount::sole();

    expect($account->status)->toBe('connected')
        ->and($account->auth_type)->toBe('oauth')
        ->and($account->google_email)->toBe('owner@example.com')
        ->and($account->refresh_token)->toBe('1//refresh-token')
        ->and(DB::table('gmc_accounts')->value('refresh_token'))->not->toBe('1//refresh-token')
        ->and(session('gmc_oauth_state'))->toBeNull();

    expect(DB::table('google_audit_logs')->where('action', 'connect')->exists())->toBeTrue();
});

it('connects a service account from an uploaded key file', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3600])]);

    $this->loginAsAdmin();

    $file = UploadedFile::fake()->createWithContent('key.json', json_encode([
        'type' => 'service_account', 'client_email' => 'sync@p.iam.gserviceaccount.com', 'private_key' => $pem,
    ]));

    post(route('admin.google_merchant.service_account'), ['key_file' => $file, 'developer_email' => 'owner@example.com'])
        ->assertRedirect(route('admin.google_merchant.connection.index'))
        ->assertSessionHas('success');

    $account = GmcAccount::sole();

    expect($account->auth_type)->toBe('service_account')
        ->and($account->google_email)->toBe('sync@p.iam.gserviceaccount.com')
        ->and($account->developer_email)->toBe('owner@example.com')
        ->and(json_decode($account->service_account_json, true)['client_email'])->toBe('sync@p.iam.gserviceaccount.com');
});

it('rejects a key file that is not a service account key', function () {
    $this->loginAsAdmin();

    post(route('admin.google_merchant.service_account'), [
        'key_file' => UploadedFile::fake()->createWithContent('key.json', '{"type":"authorized_user"}'),
    ])->assertSessionHasErrors('key_file');

    expect(GmcAccount::count())->toBe(0);
});

it('selects merchant, registers once and provisions the data source', function () {
    Http::fake([
        'merchantapi.googleapis.com/accounts/v1/accounts?*'                            => Http::response(fixture('accounts-list')),
        'merchantapi.googleapis.com/accounts/v1/accounts/123456/developerRegistration*' => Http::response(fixture('developer-registration')),
        'merchantapi.googleapis.com/datasources/v1/accounts/123456/dataSources?*'       => Http::sequence()
            ->push(fixture('datasources-list'))
            ->whenEmpty(Http::response(['dataSources' => [...fixture('datasources-list')['dataSources'], fixture('datasource-create')]])),
        'merchantapi.googleapis.com/datasources/v1/accounts/123456/dataSources'         => Http::response(fixture('datasource-create')),
    ]);

    $this->loginAsAdmin();

    $account = gmcAccount(['merchant_id' => null, 'developer_registered_at' => null, 'developer_email' => 'owner@example.com']);

    post(route('admin.google_merchant.merchant.select'), ['merchant_id' => '123456'])
        ->assertRedirect(route('admin.google_merchant.connection.index'))
        ->assertSessionHas('success');

    $account->refresh();

    $target = GmcTarget::sole();

    expect($account->merchant_id)->toBe('123456')
        ->and($account->developer_registered_at)->not->toBeNull()
        ->and($target->data_source_name)->toBe('accounts/123456/dataSources/999')
        ->and($target->countries)->toBe(['US'])
        ->and($target->currency)->toBe('USD');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'registerGcp') && $r['developerEmail'] === 'owner@example.com');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/dataSources') && $r['primaryProductDataSource']['feedLabel'] === 'US');

    $before = count(Http::recorded());

    post(route('admin.google_merchant.merchant.select'), ['merchant_id' => '123456']);

    $after = collect(Http::recorded())->slice($before)->map(fn ($pair) => $pair[0]->url());

    expect($after->filter(fn ($u) => str_contains($u, 'registerGcp')))->toBeEmpty()
        ->and($after->filter(fn ($u) => str_ends_with($u, '/dataSources')))->toBeEmpty();
});

it('refuses a merchant id the credentials cannot access', function () {
    Http::fake(['merchantapi.googleapis.com/accounts/v1/accounts?*' => Http::response(fixture('accounts-list'))]);

    $this->loginAsAdmin();

    $account = gmcAccount(['merchant_id' => null]);

    post(route('admin.google_merchant.merchant.select'), ['merchant_id' => '999'])
        ->assertSessionHas('error');

    expect($account->refresh()->merchant_id)->toBeNull();
});

it('disconnects and revokes', function () {
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

    $this->loginAsAdmin();

    $account = gmcAccount(['auth_type' => 'oauth', 'refresh_token' => 'r-1', 'service_account_json' => null]);

    delete(route('admin.google_merchant.disconnect'))
        ->assertRedirect(route('admin.google_merchant.connection.index'));

    $account->refresh();

    expect($account->status)->toBe('disconnected')
        ->and($account->access_token)->toBeNull()
        ->and($account->refresh_token)->toBeNull()
        ->and(DB::table('google_audit_logs')->where('action', 'disconnect')->exists())->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'revoke') && $r['token'] === 'r-1');
});

it('forbids admins without the connection permission', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);

    $this->loginAsAdmin(Admin::factory()->create(['role_id' => $role->id]));

    post(route('admin.google_merchant.oauth.start'))->assertStatus(401);

    expect(session('gmc_oauth_state'))->toBeNull();
});

it('reports failing setup checks for a local http store', function () {
    config(['app.url' => 'http://localhost']);

    $checks = app(SetupChecklist::class)->run(null);

    expect($checks['connected']['ok'])->toBeFalse()
        ->and($checks['public_https']['ok'])->toBeFalse()
        ->and($checks['credentials_configured']['ok'])->toBeTrue();
});
