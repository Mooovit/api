<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $box->name }} — {{ config('app.name', 'Laravel') }}</title>

    <!-- Standalone public page (API-024): no app assets, no auth, read-only -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-2xl mx-auto px-4 py-10">

        <header class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">{{ $box->name }}</h1>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @if ($box->status)
                            <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-3 py-1 text-sm">
                                <i class="fas fa-circle-dot text-xs"></i> {{ $box->status->name }}
                            </span>
                        @endif
                        @if ($box->location)
                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full px-3 py-1 text-sm">
                                <i class="fas fa-location-dot text-xs"></i> {{ $box->location->name }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="text-right text-sm text-gray-400 flex-shrink-0">
                    <i class="fas fa-box text-2xl"></i>
                </div>
            </div>
        </header>

        <section class="mt-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
                Contents @if (count($contents))<span class="text-gray-400">({{ count($contents) }})</span>@endif
            </h2>

            @if (count($contents))
                <ul class="space-y-2">
                    @foreach ($contents as $content)
                        <li class="bg-white rounded-lg shadow-sm border border-gray-200 px-4 py-3 flex items-center justify-between gap-3">
                            <span class="font-medium text-gray-800">{{ $content->name }}</span>
                            <span class="flex flex-wrap gap-2 justify-end">
                                @if ($content->status)
                                    <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-2.5 py-0.5 text-xs">
                                        {{ $content->status->name }}
                                    </span>
                                @endif
                                @if ($content->location)
                                    <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full px-2.5 py-0.5 text-xs">
                                        {{ $content->location->name }}
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 text-center">
                    <i class="far fa-folder-open text-3xl text-gray-300"></i>
                    <p class="mt-3 text-gray-500">This box is empty.</p>
                </div>
            @endif
        </section>

        <footer class="mt-8 text-center text-xs text-gray-400">
            Shared read-only — this page shows no account or team information.
        </footer>
    </div>
</body>
</html>
