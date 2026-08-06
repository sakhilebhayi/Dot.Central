@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'rounded-md border border-[#f87171]/30 bg-[#f87171]/10 p-4']) }}>
        <div class="font-medium text-[#f87171]">{{ __('Whoops! Something went wrong.') }}</div>

        <ul class="mt-3 list-disc list-inside text-sm text-[#f87171]">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
