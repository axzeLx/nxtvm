{{-- <html> with the user's appearance; guests fall back to localStorage (see partials.head) --}}
@php $u = auth()->user(); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ $u?->theme ?? 'teal' }}" data-mode="{{ $u?->mode ?? 'system' }}"
      @if ($u) data-appearance-url="{{ route('profile.appearance') }}" @endif>
