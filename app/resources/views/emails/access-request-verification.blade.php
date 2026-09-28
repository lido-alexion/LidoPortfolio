<p>Hello {{ $fullName }},</p>
<p>Please verify your email address to submit your StoX account access request:</p>
<p><a href="{{ $verificationUrl }}">Verify email and submit request</a></p>
<p>This link expires in {{ (int) config('access_requests.verification_expiry_hours', 24) }} hours and can be used once.</p>
<p>If you did not request access, you can ignore this message.</p>
