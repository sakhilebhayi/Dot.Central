{{--
    Shown with a 503 when InfoDot has switched Dot.Central off (maintenance or unavailable). It says so plainly and reads nothing of
    the control room: everything already here is kept exactly as it is. It is not a purchase or permission problem.
--}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f3d878" stroke-width="1.75"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 005.4-5.4l-2.65 2.65a1.5 1.5 0 01-2.1-2.1L14.7 6.3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'Dot.Central is not available right now')
@section('message', ($message ?? 'Dot.Central is temporarily unavailable.').' Everything recorded here is kept as it is.')

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--signal); border: 1px solid var(--signal); color: #15120a; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 2px; text-decoration: none;">Try again</a>
@endsection
