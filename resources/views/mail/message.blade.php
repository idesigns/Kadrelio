<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
</head>
<body style="font-family:Arial,sans-serif;color:#323338;background:#f5f6fa;padding:30px">
<div style="max-width:600px;margin:auto;background:#fff;padding:36px;border-radius:8px">
<strong style="font-size:22px">Kadrelio</strong>
<h1 style="font-size:23px;line-height:1.3;margin-top:30px">{{ $title }}</h1>
<p style="line-height:1.7">{{ $messageText }}</p>@if($actionUrl)<p style="margin:28px 0">
<a href="{{ $actionUrl }}" style="display:inline-block;background:#0073ea;color:#fff;padding:13px 20px;text-decoration:none;border-radius:4px">{{ $actionLabel }}</a>
</p>
<p style="font-size:12px;overflow-wrap:anywhere">Jeśli przycisk nie działa, otwórz link: <a href="{{ $actionUrl }}">{{ $actionUrl }}</a>
</p>@endif<p style="font-size:12px;color:#676879;margin-top:30px">Kadrelio · Kadry i dokumenty Twojego zespołu</p>
</div>
</body>
</html>
