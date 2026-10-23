<x-filament-panels::page>
    <style>
        .manual { max-width: 860px; font-size: 15px; line-height: 1.65; }
        .manual h2 { font-size: 20px; font-weight: 700; margin: 28px 0 10px; padding-top: 16px; border-top: 1px solid rgba(127, 127, 127, .2); }
        .manual h2:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
        .manual h3 { font-size: 17px; font-weight: 700; margin: 20px 0 8px; }
        .manual p { margin: 8px 0; }
        .manual ul, .manual ol { margin: 8px 0 8px 22px; }
        .manual ul { list-style: disc; }
        .manual ol { list-style: decimal; }
        .manual li { margin: 4px 0; }
        .manual li > ul, .manual li > ol { margin-top: 4px; }
        .manual code { background: rgba(245, 158, 11, .15); border-radius: 4px; padding: 1px 5px; font-size: 14px; }
        .manual .table-wrap { overflow-x: auto; margin: 12px 0; }
        .manual table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 640px; }
        .manual th, .manual td { border: 1px solid rgba(127, 127, 127, .3); padding: 8px 10px; vertical-align: top; text-align: left; }
        .manual th { background: rgba(245, 158, 11, .12); font-weight: 700; }
        @media print {
            .fi-topbar, .fi-sidebar, .fi-header { display: none !important; }
            .manual { max-width: none; }
        }
    </style>

    <div class="manual">
        {!! str_replace(['<table>', '</table>'], ['<div class="table-wrap"><table>', '</table></div>'], (string) $this->manualHtml()) !!}
    </div>
</x-filament-panels::page>
