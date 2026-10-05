{{ $notificationTitle }}

@if(!empty($items))
@foreach($items as $item)
{{ $item['title'] }}
{{ $item['message'] }}

@endforeach
@else
{{ $notificationMessage }}
@endif
@if(!empty($primaryAction['url']))

Open in StoX: {{ $primaryAction['url'] }}
@endif
