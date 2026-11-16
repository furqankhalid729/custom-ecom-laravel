<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 12px;font-size:20px;">@lang('google_merchant::app.mail.digest.title')</h1>

        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">@lang('google_merchant::app.mail.digest.intro')</p>

        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px;">
            @foreach ($counts as $key => $count)
                <tr>
                    <td style="padding:6px 0;border-bottom:1px solid #e5e7eb;">@lang("google_merchant::app.mail.digest.counts.$key")</td>
                    <td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold;">{{ number_format($count) }}</td>
                </tr>
            @endforeach
        </table>

        @if ($problems)
            <h2 style="margin:0 0 8px;font-size:16px;">@lang('google_merchant::app.mail.digest.top-problems')</h2>

            <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;">
                @foreach ($problems as $problem)
                    <tr>
                        <td style="padding:8px 8px 8px 0;border-bottom:1px solid #e5e7eb;vertical-align:top;">
                            <strong>{{ $problem['description'] }}</strong>
                            @if ($problem['hint'])
                                <br><span style="color:#4b5563;">{{ $problem['hint'] }}</span>
                            @endif
                        </td>
                        <td style="padding:8px 0;border-bottom:1px solid #e5e7eb;vertical-align:top;text-align:right;white-space:nowrap;">
                            @lang('google_merchant::app.mail.digest.products', ['count' => number_format($problem['count'])])
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif

        <a href="{{ $url }}" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#ffffff;border-radius:6px;text-decoration:none;font-size:14px;">
            @lang('google_merchant::app.mail.digest.button')
        </a>
    </div>
</body>
</html>
