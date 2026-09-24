<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    @if ($fontsUrl)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <link rel="stylesheet" href="{{ $cssUrl }}">
    <style>{!! $tokensCss !!} html,body{margin:0;padding:0;background:var(--e-global-color-aisgbackground,#fff)}</style>
    <script src="{{ $runtimeUrl }}"></script>
    <script src="{{ $alpineUrl }}" defer></script>
</head>
<body>
{!! $body !!}
</body>
</html>
