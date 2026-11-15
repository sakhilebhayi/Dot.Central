<x-app-layout>
    <x-slot name="header">{{ __('API Tokens') }}</x-slot>

    <h1 class="sr-only">{{ __('API Tokens') }}</h1>
    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            @livewire('api.api-token-manager')
        </div>
    </div>
</x-app-layout>
