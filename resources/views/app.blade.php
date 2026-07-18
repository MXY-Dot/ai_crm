<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>Gravity AI CRM</title>
    @inertiaHead
    @unless (app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body>
    @inertia
</body>
</html>