<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} - Label Print Station</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Axios for API calls -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <!-- DYMO Connect framework 2.x (vendored, API-038) + shared service -->
    <script src="{{ asset('js/vendor/dymo.connect.framework.js') }}"></script>
    <script src="{{ asset('js/dymo.js') }}?v=1"></script>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
        }
        .scan-input {
            font-family: 'Courier New', monospace;
        }
        /* Touch-to-print row states */
        .print-row { transition: background-color 0.3s ease; cursor: pointer; }
        .print-row.state-printing { background-color: #fef3c7; }
        .print-row.state-printed  { background-color: #d1fae5; }
        .print-row.state-failed   { background-color: #fee2e2; }
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 60;
            transition: opacity 0.3s ease;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-100 to-indigo-200 min-h-screen">
    <div class="max-w-3xl mx-auto px-4 py-6">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-print text-indigo-600 mr-2"></i>Label Print Station
                </h1>
                <p class="text-sm text-gray-600">{{ $teamName }}</p>
            </div>
            <a href="/kanban/status" class="bg-white/80 hover:bg-white text-gray-700 px-3 py-2 rounded-lg text-sm font-medium transition">
                <i class="fas fa-arrow-left mr-1"></i>Board
            </a>
        </div>

        <!-- DYMO status strip (API-038) -->
        <div class="bg-white/90 backdrop-blur rounded-xl shadow p-4 mb-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div id="dymoStatus" class="flex items-center gap-2 text-sm text-gray-500">
                    <i class="fas fa-spinner fa-spin text-gray-400"></i>
                    Checking DYMO Connect&hellip;
                </div>
                <div id="dymoPrefs" class="hidden items-center gap-2">
                    <select id="printerSelect" title="LabelWriter printer"
                            class="text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:ring-2 focus:ring-indigo-500"></select>
                    <select id="templateSelect" title="Label stock (template)"
                            class="text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:ring-2 focus:ring-indigo-500"></select>
                    <button onclick="location.reload()" title="Re-check DYMO service"
                            class="text-gray-400 hover:text-indigo-600 px-1"><i class="fas fa-sync-alt"></i></button>
                </div>
            </div>
        </div>

        <!-- Scan a box -->
        <div class="bg-white/90 backdrop-blur rounded-xl shadow-lg p-5 mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="fas fa-qrcode text-indigo-600 mr-1"></i>Scan box QR (or type a uuid)
            </label>
            <div class="flex gap-2">
                <input type="text" id="labelScanInput" placeholder="Scan barcode&hellip;" autofocus autocomplete="off"
                       class="scan-input flex-1 px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <button onclick="scanBox()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition">
                    <i class="fas fa-search mr-1"></i>Load
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-2">Any item's sticker scans here too — boxes show their contents, single items offer their own label.</p>
        </div>

        <!-- Loaded box -->
        <div id="boxPanel" class="hidden">
            <div id="boxHeader" class="bg-white/90 backdrop-blur rounded-xl shadow p-5 mb-4"></div>
            <div id="contentsList" class="space-y-3"></div>
        </div>

        <!-- Idle hint -->
        <div id="emptyHint" class="text-center text-gray-500 py-16">
            <i class="fas fa-box-open text-5xl mb-4 opacity-40"></i>
            <p>Scan a box to see what's inside.</p>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast hidden px-4 py-2 rounded-lg shadow-lg text-sm font-medium text-white"></div>

    <script src="{{ asset('js/label-print.js') }}?v=1"></script>
</body>
</html>
