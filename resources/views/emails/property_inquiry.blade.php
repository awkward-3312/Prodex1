<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Property Inquiry</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:600px;width:100%;">
          <tr>
            <td style="background:#0d6efd;padding:20px 28px;color:#ffffff;font-size:18px;font-weight:bold;">
              {{ $data['store_name'] ?? 'Online Store' }} — New Property Inquiry
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              @if(!empty($data['property_title']))
                <p style="margin:0 0 16px;font-size:15px;">
                  A visitor sent an inquiry about
                  <strong>{{ $data['property_title'] }}</strong>.
                </p>
                @if(!empty($data['property_url']))
                  <p style="margin:0 0 20px;">
                    <a href="{{ $data['property_url'] }}" style="color:#0d6efd;">View property</a>
                  </p>
                @endif
              @else
                <p style="margin:0 0 20px;font-size:15px;">You received a new property inquiry.</p>
              @endif

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                <tr>
                  <td style="padding:8px 0;width:120px;color:#6b7280;">Name</td>
                  <td style="padding:8px 0;font-weight:bold;">{{ $data['name'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#6b7280;">Email</td>
                  <td style="padding:8px 0;">{{ $data['email'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#6b7280;">Phone</td>
                  <td style="padding:8px 0;">{{ $data['phone'] ?? '-' }}</td>
                </tr>
              </table>

              <div style="margin-top:20px;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #eef0f3;">
                <div style="color:#6b7280;font-size:12px;margin-bottom:6px;">Message</div>
                <div style="font-size:14px;white-space:pre-wrap;">{{ $data['message'] ?? '' }}</div>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 28px;background:#f9fafb;color:#9ca3af;font-size:12px;">
              This message was sent automatically from your storefront's Real Estate theme.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
