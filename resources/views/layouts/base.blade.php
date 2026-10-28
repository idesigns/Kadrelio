<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Kadrelio') · Kadrelio</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    <link rel="icon" href="{{ asset('kadrelio-icon.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@yield('body-class')">
    @yield('body')
    <template id="select-chevron-icon"><x-icon name="chevron" :size="16" /></template>
    <div class="toast" id="toast" role="status" aria-live="polite">
</div>
</body>
</html>
