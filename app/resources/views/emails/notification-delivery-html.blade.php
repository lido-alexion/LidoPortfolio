<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $notificationTitle }}</title></head>
<body style="margin:0;background:#f4f6f8;color:#17212b;font-family:Arial,sans-serif;line-height:1.5">
<main style="max-width:600px;margin:24px auto;padding:24px;background:#fff;border:1px solid #dce2e8;border-radius:8px">
    <p style="margin-top:0;color:#315c82;font-weight:700">StoX</p>
    <h1 style="font-size:20px">{{ $notificationTitle }}</h1>
    @if(!empty($items))
        @foreach($items as $item)
            <section style="border-top:1px solid #e6eaee;padding:12px 0">
                <h2 style="font-size:16px">{{ $item['title'] }}</h2>
                <p>{{ $item['message'] }}</p>
            </section>
        @endforeach
    @else
        <p>{{ $notificationMessage }}</p>
    @endif
    @if(!empty($primaryAction['url']))
        <p><a href="{{ $primaryAction['url'] }}">{{ $primaryAction['label'] ?? 'Open in StoX' }}</a></p>
    @endif
    <p style="color:#64717e;font-size:12px;border-top:1px solid #e6eaee;padding-top:12px">Sign in to StoX to review this notification.</p>
</main>
</body>
</html>
