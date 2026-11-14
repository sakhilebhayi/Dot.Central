{{--
  <x-dot.bell>: the notifications bell for the header's `tools` slot. A 44px link with a count badge; the count is capped ("99+"), the
  accessible name carries the real number. The platform owns where it goes and what the count is.

  Props
    href    where it goes (default '#')
    count   unread number (0, null or '' = no badge)
    label   accessible name stem (default "Notifications"): "Notifications, 12 unread"
    max     the cap shown in the badge (default 99)
  Other attributes (wire:navigate, data-*, class) land on the <a>. Hidden under 480px, where the drawer carries the same link.
--}}
@props([
    'href' => '#',
    'count' => 0,
    'label' => 'Notifications',
    'max' => 99,
])
@php
    $n = is_numeric($count) ? (int) $count : 0;
    $shown = $n > (int) $max ? $max.'+' : (string) $n;
@endphp
<a {{ $attributes->class(['dh-icon-btn', 'dh-bell'])->merge(['href' => $href]) }} aria-label="{{ $n > 0 ? $label.', '.$n.' unread' : $label }}">
    <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4"/></svg>
    @if ($n > 0)<span class="dh-badge" aria-hidden="true">{{ $shown }}</span>@endif
</a>
