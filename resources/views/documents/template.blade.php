<!doctype html>
<html lang="{{ strtolower($template->language) }}">
<head><meta charset="utf-8"><title>{{ $template->name }}</title>
<style>@page{size:A4;margin:22mm}body{font-family:Arial,sans-serif;color:#20212b;font-size:11pt;line-height:1.55}h1{font-size:17pt;margin:0 0 22pt}.document-body{white-space:pre-wrap;overflow-wrap:anywhere}</style></head>
<body><h1>{{ $template->name }}</h1><div class="document-body">{{ $body }}</div></body>
</html>
