<?php

namespace App\View\Components;

use Carbon\Carbon;
use Illuminate\View\Component;
use Illuminate\View\View;

class RelativeDate extends Component
{
    public function __construct(
        public ?Carbon $date = null,
        public string $fallback = '—',
        public bool $withYear = false,
        public bool $showTime = true,
    ) {}

    public function render(): View
    {
        return view('components.relative-date');
    }
}
