<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap">
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <link rel="stylesheet" href="https://www.riccardotartaglia.it/jkanban/dist/jkanban.min.css">
    <!-- Scripts -->
    @routes
    <script src="{{ mix('js/app.js') }}" defer></script>
</head>
<body class="font-sans antialiased">
<div id="kanban"></div>
<script>
    window.addEventListener("DOMContentLoaded", (event) => {
        window.axios.get('/data', {withCredentials: true}).then(result => {
            console.log(result);
        })
        window.kanban = new window.jKanban({
            element          : '#kanban',                                           // selector of the kanban container
            gutter           : '15px',                                       // gutter of the board
            widthBoard       : '250px',                                      // width of the board
            responsivePercentage: false,                                    // if it is true I use percentage in the width of the boards and it is not necessary gutter and widthBoard
            dragItems        : true,                                         // if false, all items are not draggable
            boards           : JSON.parse("{{ $jsonData }}".replace(/&quot;/g,'"')),                                          // json of boards
            dragBoards       : true,                                         // the boards are draggable, if false only item can be dragged
            itemAddOptions: {
                enabled: false,                                              // add a button to board for easy item creation
                content: '+',                                                // text or html content of the board button
                class: 'kanban-title-button btn btn-default btn-xs',         // default class of the button
                footer: false                                                // position the button on footer
            },
            itemHandleOptions: {
                enabled             : false,                                 // if board item handle is enabled or not
                handleClass         : "item_handle",                         // css class for your custom item handle
                customCssHandler    : "drag_handler",                        // when customHandler is undefined, jKanban will use this property to set main handler class
                customCssIconHandler: "drag_handler_icon",                   // when customHandler is undefined, jKanban will use this property to set main icon handler class. If you want, you can use font icon libraries here
                customHandler       : "<span class='item_handle'>+</span> %title% "  // your entirely customized handler. Use %title% to position item title
                                                                                     // any key's value included in item collection can be replaced with %key%
            },
            click            : function (el) {},                             // callback when any board's item are clicked
            context          : function (el, event) {},                      // callback when any board's item are right clicked
            dragEl           : function (el, source) {},                     // callback when any board's item are dragged
            dragendEl        : function (el) {

            },                             // callback when any board's item stop drag
            dropEl           : function (el, target, source, sibling) {
                console.log("ItemId", el.dataset.eid);
                console.log("NewStatus", target.parentElement.dataset.id);
                window.axios.patch(`/item/${el.dataset.eid}`, {
                    "{{ $field }}": target.parentElement.dataset.id,
                }, { withCredentials: true}).then(response => {
                    console.log(response);
                })
            },    // callback when any board's item drop in a board
            dragBoard        : function (el, source) {},                     // callback when any board stop drag
            dragendBoard     : function (el) {},                             // callback when any board stop drag
            buttonClick      : function(el, boardId) {},                     // callback when the board's button is clicked
            propagationHandlers: [],                                         // the specified callback does not cancel the browser event. possible values: "click", "context"
        })
    });
</script>
</body>
</html>
