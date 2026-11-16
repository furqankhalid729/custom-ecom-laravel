<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 12px;font-size:20px;">@lang('google_merchant::app.mail.reauth.title')</h1>

        <p style="margin:0 0 12px;font-size:14px;line-height:1.5;">
            @lang('google_merchant::app.mail.reauth.body', ['merchant' => $account->display_name ?: $account->merchant_id])
        </p>

        @if ($account->last_error)
            <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">{{ $account->last_error }}</p>
        @endif

        <a href="{{ $url }}" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#ffffff;border-radius:6px;text-decoration:none;font-size:14px;">
            @lang('google_merchant::app.mail.reauth.button')
        </a>
    </div>
</body>
</html>
